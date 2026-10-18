/**
 * FAZ Cookie Manager - IAB TCF v2.3 CMP
 *
 * Provides the window.__tcfapi() surface that ad-tech scripts expect.
 * Maps FAZ consent categories to TCF Purposes and generates a TC string
 * with core segment + mandatory DisclosedVendors segment (TCF v2.3).
 *
 * When GVL data is provided via _fazTcfConfig, encodes real vendor consent
 * (from the visitor's explicit vendor record) and legitimate interest
 * bitfields (off until the visitor has chosen, then established unless the
 * visitor exercised the Right to Object).
 */
(function () {
	"use strict";

	var cfg = window._fazTcfConfig || {};

	var CMP_ID             = cfg.cmpId || 0;
	var CMP_VERSION        = 1;
	var TCF_VERSION        = 2;
	var VENDOR_LIST        = cfg.gvlVersion || 0;
	var MAX_PURPOSE        = 11; // GVL v3 has 11 standard purposes
	var TCF_POLICY_VERSION = 5;  // GVL v3 tcfPolicyVersion
	var PURPOSE_ONE_TREATMENT = !!cfg.purposeOneTreatment;
	var BASE64URL          = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_";

	// Selected vendor IDs and vendor details from server-side GVL.
	var SELECTED_VENDORS = cfg.selectedVendors || [];
	var VENDORS          = cfg.vendors || {};

	/**
	 * Push a value as `length` bits into the bits array (MSB first).
	 */
	function cookieName(name) {
		return ((window._fazConfig || {})._cookieNames || {})[name] || name;
	}
	function readCookie(name) {
		var prefix = cookieName(name) + '=';
		var parts = document.cookie.split(';');
		for (var i = 0; i < parts.length; i++) {
			var part = parts[i].trim();
			if (part.indexOf(prefix) === 0) return [part, part.substring(prefix.length)];
		}
		return null;
	}

	function pushBits(bits, value, length) {
		if (length > 32) {
			var highLen = length - 32;
			var highVal = Math.floor(value / 4294967296);
			pushBits(bits, highVal, highLen);
			pushBits(bits, value >>> 0, 32);
			return;
		}
		var s = (value >>> 0).toString(2);
		while (s.length < length) s = "0" + s;
		s = s.substring(s.length - length);
		for (var k = 0; k < length; k++) bits.push(s.charAt(k) === "1" ? 1 : 0);
	}

	/**
	 * Convert a bits array to a base64url string (padded to 6-bit boundary).
	 */
	function bitsToBase64url(bits) {
		while (bits.length % 6 !== 0) bits.push(0);
		var str = "";
		for (var i = 0; i < bits.length; i += 6) {
			var val = 0;
			for (var b = 0; b < 6; b++) {
				val = (val << 1) | (bits[i + b] || 0);
			}
			str += BASE64URL.charAt(val);
		}
		return str;
	}

	function charTo6(c) {
		return c.toUpperCase().charCodeAt(0) - 65;
	}

	// Map FAZ category slugs → TCF Purpose IDs
	// NOTE: `necessary` maps to NOTHING on purpose. TCF Purpose 1 ("Store and/or
	// access information on a device") is a consent-basis purpose; the strictly-
	// necessary category is consent-EXEMPT precisely because it does not rely on
	// it. Mapping the two mints a consent the visitor never gave — see
	// buildPurposeConsent() for how Purpose 1 is actually decided.
	var CATEGORY_TO_PURPOSES = {
		functional:    [5, 6, 11],
		analytics:     [8, 9, 10],
		performance:   [8, 9],
		marketing:     [2, 3, 4, 7]
	};

	// Event listeners
	var listeners   = {};
	var listenerId  = 0;
	var cmpLoaded   = false;
	var cmpStatus   = "loading";
	var displayOpen = false;

	function base64urlToBits(str) {
		var bits = [];
		for (var i = 0; i < str.length; i++) {
			var value = BASE64URL.indexOf(str.charAt(i));
			if (value === -1) return null;
			for (var bit = 5; bit >= 0; bit--) {
				bits.push((value >> bit) & 1);
			}
		}
		return bits;
	}

	function readBits(bits, offset, length) {
		var value = 0;
		for (var i = 0; i < length; i++) {
			value = (value * 2) + (bits[offset + i] || 0);
		}
		return value;
	}

	var DECISECONDS_PER_DAY = 864000;

	/**
	 * The UTC midnight of the day containing `ms`, in deciseconds — the only
	 * value the TC String format allows in Created and LastUpdated.
	 */
	function utcMidnightDeciseconds(ms) {
		var d = new Date(ms);
		return Math.round(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()) / 100);
	}

	function readTcTimestamps() {
		var match = readCookie("euconsent-v2");
		if (!match) return null;
		var coreSegment = match[1].split(".")[0];
		if (!coreSegment) return null;
		var bits = base64urlToBits(coreSegment);
		if (!bits || bits.length < 78) return null;
		if (readBits(bits, 0, 6) !== TCF_VERSION) return null;
		return {
			created: readBits(bits, 6, 36),
			lastUpdated: readBits(bits, 42, 36)
		};
	}

	function readConsentCookiePairs() {
		var match = readCookie("fazcookie-consent");
		if (!match) return null;
		var value = match[1];
		try {
			value = decodeURIComponent(value);
		} catch (_unused) { /* raw legacy cookie */ }

		return value.split(",").reduce(function(acc, pair) {
			var trimmed = pair.trim();
			// Match PHP's faz_parse_consent_cookie() which splits on the
			// FIRST colon via explode(':', $pair, 2). Using lastIndexOf here
			// would diverge for any value containing a colon.
			var sepIdx = trimmed.indexOf(":");
			if (sepIdx === -1) return acc;
			var key = trimmed.substring(0, sepIdx).trim();
			if (!key) return acc;
			acc[key] = trimmed.substring(sepIdx + 1).trim();
			return acc;
		}, {});
	}

	function isConsentCookieStale(pairs) {
		if (!pairs) return false;
		var config = window._fazConfig || {};
		// Defensive only. The server never activates the cached jurisdiction
		// shell while IAB TCF is on (Frontend::get_geo_bootstrap_status() returns
		// reason 'iab', pinned by test-geo-cache-bootstrap-php.php), and this
		// file is enqueued only when TCF is on, so a page that loads the CMP has
		// no pending geographic scope to wait for. The gate stays fail-closed for
		// a `faz_store_data` filter that forces _geoBootstrap anyway; such a
		// setup gets no re-announcement after resolution (no tcloaded, no
		// euconsent-v2 rewrite) and is not supported.
		if (config._geoBootstrap && !config._geoBootstrapResolved) return true;
		if (config._siteScopeBound && !isPmpNecessaryOnly(pairs) && isSiteBindingBroken(pairs, config)) return true;
		// Coerce because wp_localize_script may stringify integers.
		var serverRevisionRaw = config && config._consentRevision;
		var serverRevision = parseInt(serverRevisionRaw, 10);
		if (isNaN(serverRevision) || serverRevision < 1) serverRevision = 1;
		var storedRevision = parseInt(pairs.rev, 10);
		return (serverRevision > 1 && (isNaN(storedRevision) || storedRevision < serverRevision)) || isConsentCookieOutOfScope(pairs, config);
	}

	// Share the banner/law decision with PHP, GCM and the main runtime.
	function readScopedCookieValue(parsed, key, legacyKey, strict) {
	    var primary = parsed[key] || "";
	    if (primary || strict) return primary;
	    var legacy = parsed[legacyKey] || "";
	    if (!legacy || /^(yes|no)(:.*)?$/i.test(legacy)) return "";
	    return legacy;
	}

	// Multisite site binding, twin of gcm.js and script.js: the fingerprint must
	// be present and, when the record names the banner and law served here,
	// equal the published one. Other records are verified by the server's HMAC.
	function isSiteBindingBroken(parsed, config) {
	    var storedFp = parsed["__scope.fp"] || "";
	    if (!storedFp) return true;
	    var sameScope = (parsed["__scope.banner"] || "") === (config._bannerSlug ? String(config._bannerSlug) : "") &&
	        (parsed["__scope.law"] || "") === (config._activeLaw ? String(config._activeLaw) : "");
	    return sameScope && storedFp !== (config._scopeFingerprint || "");
	}

	function isPmpNecessaryOnly(parsed) {
	    return parsed.source === "pmp" && parsed.action === "auto" && parsed.consent === "no" && !Object.keys(parsed).some(function(key) { return key !== "necessary" && /^yes(?::|$)/.test(parsed[key]); });
	}

	function isConsentCookieOutOfScope(parsed, config) {
	    if (!config) return false;
	    if (isPmpNecessaryOnly(parsed)) return false;
	    var strict = !!config._strictScopeFp;
	    var currentSlug = config._bannerSlug ? String(config._bannerSlug) : "";
	    var currentLaw = config._activeLaw ? String(config._activeLaw) : "";
	    var currentFp = typeof config._scopeFingerprint === "string" ? config._scopeFingerprint : "";
	    var storedFp = parsed["__scope.fp"] || "";
	    var storedSlug = readScopedCookieValue(parsed, "__scope.banner", "banner", strict);
	    var storedLaw = readScopedCookieValue(parsed, "__scope.law", "law", strict);
	    if (strict && !storedFp) return true;
	    // Pre-1.14 cookie, no scope recorded: the existing consent stands.
	    if (!storedSlug && !storedLaw && !storedFp) return false;
	    // Without geographic routing only a change of law re-asks: a new banner
	    // slug or fingerprint (A/B test ended, salts rotated) does not.
	    if (!(config._geoRouting || config._geoBootstrap)) {
	        return !!(currentLaw && storedLaw && storedLaw !== currentLaw);
	    }
	    if (storedFp && currentFp && storedFp !== currentFp) return true;
	    return !!(
	        (currentSlug && storedSlug && storedSlug !== currentSlug) ||
	        (currentLaw && storedLaw && storedLaw !== currentLaw)
	    );
	}

	/**
	 * Read the FAZ consent cookie and return a category→boolean map.
	 */
	function readConsent() {
		// `_acted` records whether this visitor has actually made a choice. It is
		// NOT a category — it is the difference between "no consent yet" and "the
		// visitor decided". Purpose 1 turns on it, so a pre-banner pageview can no
		// longer be presented to vendors as a settled record (#compliance-audit).
		var consent = { necessary: true, _acted: false };
		var pairs = readConsentCookiePairs();
		if (!pairs || isConsentCookieStale(pairs)) return consent;
		// A record a privacy signal created before the visitor answered the
		// banner (undecided:1) is not a choice, so it is not an action either.
		consent._acted = pairs.action === "yes" && pairs.undecided !== "1";

		Object.keys(pairs).forEach(function(key) {
			var val = pairs[key];
			if (key === "necessary" || key === "functional" || key === "analytics" ||
				key === "performance" || key === "marketing" || key === "advertisement") {
				consent[key === "advertisement" ? "marketing" : key] = val === "yes";
			}
		});
		return consent;
	}

	/**
	 * Decode a vendor bitfield written by script.js (_fazEncodeVendorBits):
	 * bit (id - 1), most significant first, six bits per base64url character —
	 * the TC string's own alphabet and order. null when the text is not one.
	 */
	function decodeVendorBits(text) {
		if (typeof text !== "string") return null;
		var ids = [];
		for (var i = 0; i < text.length; i++) {
			var value = BASE64URL.indexOf(text.charAt(i));
			if (value === -1) return null;
			for (var bit = 0; bit < 6; bit++) {
				if (value & (32 >> bit)) ids.push(i * 6 + bit + 1);
			}
		}
		return ids;
	}

	/**
	 * Read vendor-level consent from cookie (if present): id → consented, for
	 * every vendor the record names. Two formats (script.js
	 * _fazParseVendorRecord is the twin):
	 *   compact  fazVendorConsent=d:<bitfield>,c:<bitfield|*>
	 *            d = vendors disclosed when the visitor chose, c = consented,
	 *            "*" = every disclosed vendor (Accept All);
	 *   legacy   fazVendorConsent=1:yes,2:no,5:yes (one pair per disclosed vendor).
	 * The legacy record was not written at all past 3800 bytes, i.e. Accept All
	 * with a few hundred selected vendors left none and every vendor read as
	 * not consented. A damaged compact record reads as no record (fail closed).
	 */
	function readVendorConsent() {
		var consentPairs = readConsentCookiePairs();
		if (!consentPairs || isConsentCookieStale(consentPairs)) return {};

		var vendorConsent = {};
		var match = readCookie("fazVendorConsent");
		if (!match) return vendorConsent;

		var compactDisclosed = null;
		var compactConsent = null;
		var legacy = {};
		var pairs = match[1].split(",");
		for (var i = 0; i < pairs.length; i++) {
			var idx = pairs[i].indexOf(":");
			if (idx === -1) continue;
			var key = pairs[i].substring(0, idx).trim();
			var value = pairs[i].substring(idx + 1).trim();
			if (key === "d") { compactDisclosed = value; continue; }
			if (key === "c") { compactConsent = value; continue; }
			var id = parseInt(key, 10);
			if (id > 0) legacy[id] = value === "yes";
		}
		if (compactDisclosed === null) return legacy;

		var disclosedIds = decodeVendorBits(compactDisclosed);
		if (!disclosedIds) return vendorConsent;
		var all = compactConsent === "*";
		var granted = {};
		var consentIds = all ? [] : (decodeVendorBits(compactConsent || "") || []);
		for (var c = 0; c < consentIds.length; c++) granted[consentIds[c]] = true;
		for (var d = 0; d < disclosedIds.length; d++) {
			vendorConsent[disclosedIds[d]] = all || granted[disclosedIds[d]] === true;
		}
		return vendorConsent;
	}

	/**
	 * Build purpose consent bit-set from FAZ categories.
	 * Returns an object { "1": true, "2": false, ... }
	 */
	function buildPurposeConsent(categoryConsent) {
		var purposes = {};
		for (var p = 1; p <= MAX_PURPOSE; p++) {
			purposes[String(p)] = false;
		}

		for (var cat in CATEGORY_TO_PURPOSES) {
			if (!CATEGORY_TO_PURPOSES.hasOwnProperty(cat)) continue;
			if (categoryConsent[cat]) {
				var ids = CATEGORY_TO_PURPOSES[cat];
				for (var j = 0; j < ids.length; j++) {
					purposes[String(ids[j])] = true;
				}
			}
		}
		// Purpose 1 is earned, not assumed. It used to read
		// `!!categoryConsent.necessary`, and readConsent() returns necessary:true
		// unconditionally — so every TC string this CMP ever built carried
		// Purpose 1 granted, from the first pre-banner pageview through an
		// explicit Reject All. That is exactly what an IAB validator decodes.
		//
		// Correct basis: the visitor made a choice AND that choice granted at
		// least one purpose that genuinely needs device storage. Reject All
		// therefore clears it, and so does a pageview before the banner is
		// answered.
		var grantedNonNecessary = false;
		for (var pid in purposes) {
			if (purposes.hasOwnProperty(pid) && purposes[pid] && pid !== "1") {
				grantedNonNecessary = true;
				break;
			}
		}
		purposes["1"] = PURPOSE_ONE_TREATMENT ? false : (!!categoryConsent._acted && grantedNonNecessary);
		return purposes;
	}

	/**
	 * Build special feature opt-ins.
	 *
	 * IAB TCF v2.2 requires a separate, explicit opt-in for each Special Feature
	 * (SF1: precise geolocation, SF2: device scanning) — they cannot be derived
	 * from Purpose/category consent.  Since the FAZ banner has no dedicated SF
	 * toggle, we always return false.  This keeps the TC string compliant.
	 */
	function buildSpecialFeatureOptins() {
		return { "1": false, "2": false };
	}

	/**
	 * Read the Right-to-Object record written by script.js when the visitor
	 * saves the IAB section of the preference center.
	 *
	 * Format: fazVendorLI=p2:no,p7:yes,v:<bitfield>
	 *   p<purposeId>  → purpose-level switch
	 *   v             → bitfield of the vendors the visitor objected to (only
	 *                   objected vendors are recorded, so an absent vendor
	 *                   means "not objected")
	 *   <vendorId>    → the same, one `<vendorId>:no` pair per objected vendor,
	 *                   as records written before 1.34.2 carry it
	 *   "no" = the visitor objected, "yes" = legitimate interest stays established.
	 *
	 * Returns null when there is no record at all — the visitor never saw the
	 * LI controls, or the consent cookie is gone or stale — so callers can tell
	 * "no record" from "nothing objected".
	 */
	function readLIObjections() {
		var consentPairs = readConsentCookiePairs();
		if (!consentPairs || isConsentCookieStale(consentPairs)) return null;

		var match = readCookie("fazVendorLI");
		if (!match) return null;

		var record = { purposes: {}, vendors: {} };
		var pairs = match[1].split(",");
		for (var i = 0; i < pairs.length; i++) {
			var kv = pairs[i].split(":");
			if (kv.length !== 2) continue;
			var key = kv[0].trim();
			var established = kv[1].trim() === "yes";
			if (key === "v") {
				var objected = decodeVendorBits(kv[1].trim()) || [];
				for (var o = 0; o < objected.length; o++) record.vendors[objected[o]] = false;
			} else if (key.charAt(0) === "p") {
				var pid = parseInt(key.substring(1), 10);
				if (!isNaN(pid)) record.purposes[pid] = established;
			} else {
				var vid = parseInt(key, 10);
				if (!isNaN(vid)) record.vendors[vid] = established;
			}
		}
		return record;
	}

	/**
	 * Build vendor consent map.
	 *
	 * A vendor is consented only when the visitor's explicit record says so.
	 * The record (fazVendorConsent) is written by script.js with one entry per
	 * vendor that was DISCLOSED in the preference center at the moment of the
	 * choice. A vendor absent from it was never shown to this visitor — the
	 * operator added it later, the first GVL download auto-selected it, or TCF
	 * was switched on after the visitor had already answered the banner — and
	 * consent that is informed about recipients (GDPR Art. 4(11), EDPB 05/2020
	 * §64) cannot be minted for it from an earlier category choice. This used
	 * to derive "consented if every consent purpose is consented" for exactly
	 * those vendors, and rewrite euconsent-v2 with the result on the next
	 * pageview while the preference center showed the same vendor unticked.
	 *
	 * A vendor id the GVL in use does not carry is never consented, whatever an
	 * older record says: a de-registered vendor has no Framework basis left.
	 */
	function buildVendorConsent() {
		var vendorConsent = {};
		var explicit = readVendorConsent();

		for (var i = 0; i < SELECTED_VENDORS.length; i++) {
			var vid = SELECTED_VENDORS[i];
			if (!VENDORS[vid]) {
				vendorConsent[vid] = false;
				continue;
			}
			vendorConsent[vid] = explicit[vid] === true;
		}
		return vendorConsent;
	}

	/**
	 * Build the PurposesLegitimateInterest and VendorLegitimateInterest maps.
	 *
	 * Per IAB TCF, legitimate interest is established BY DEFAULT and ends when
	 * the visitor exercises the Right to Object (TCF Policies, Appendix B;
	 * GDPR Art. 21) — a signal distinct from consent. The preference center
	 * exposes that right per purpose and per vendor, and records it in the
	 * fazVendorLI cookie (readLIObjections). The rules, in order:
	 *
	 *  - A purpose is objected when the record says so, or when the visitor
	 *    pressed Reject All without an LI record (a choice recorded before the
	 *    LI controls existed): Reject All is an objection to everything, which
	 *    is the EDPB/CNIL reading and the only safe one for a legacy record.
	 *  - A vendor keeps LI only when the visitor did not object to it, did not
	 *    switch it off, and it was actually disclosed (present in the vendor
	 *    record once the visitor acted). A vendor switched OFF loses LI as
	 *    well — nobody who turned a vendor off meant "but keep processing my
	 *    data under legitimate interest".
	 *  - Vendor LI needs at least one of its LI purposes still established;
	 *    purpose LI is established only if at least one vendor with LI still
	 *    declares it. The two bitfields therefore agree.
	 *
	 *  - Before the visitor has made a choice (no decided record, or an
	 *    undecided one written by a privacy signal) nothing is signalled:
	 *    every LI bit is off. That is what the preference centre shows —
	 *    _fazLIDefaultEstablished() in script.js keeps every LI switch off
	 *    until a choice — and the API must not answer a vendor with an
	 *    established legitimate interest the visitor is being shown as unset.
	 *    The string is not persisted before a choice either.
	 */
	function buildLegitimateInterest(categoryConsent) {
		var record   = readLIObjections();
		var explicit = readVendorConsent();
		var acted    = !!(categoryConsent && categoryConsent._acted);

		var rejectedAll = false;
		if (acted && !record) {
			rejectedAll = true;
			for (var cat in categoryConsent) {
				if (!categoryConsent.hasOwnProperty(cat)) continue;
				if (cat === "necessary" || cat === "_acted") continue;
				if (categoryConsent[cat]) { rejectedAll = false; break; }
			}
		}

		function purposeObjected(pid) {
			if (rejectedAll) return true;
			return !!(record && record.purposes[pid] === false);
		}

		var purposeLI = {};
		for (var p = 1; p <= 24; p++) purposeLI[String(p)] = false;
		var vendorLI = {};
		if (!acted) {
			for (var k = 0; k < SELECTED_VENDORS.length; k++) vendorLI[SELECTED_VENDORS[k]] = false;
			return { purposeLI: purposeLI, vendorLI: vendorLI };
		}

		for (var i = 0; i < SELECTED_VENDORS.length; i++) {
			var vid = SELECTED_VENDORS[i];
			var v = VENDORS[vid];
			if (!v || !v.legIntPurposes || v.legIntPurposes.length === 0) {
				vendorLI[vid] = false;
				continue;
			}
			var eligible = !rejectedAll;
			if (record && record.vendors[vid] === false) eligible = false;
			if (acted && explicit[vid] !== true) eligible = false;

			var hasLI = false;
			if (eligible) {
				for (var j = 0; j < v.legIntPurposes.length; j++) {
					var pid = v.legIntPurposes[j];
					if (purposeObjected(pid)) continue;
					hasLI = true;
					purposeLI[String(pid)] = true;
				}
			}
			vendorLI[vid] = hasLI;
		}
		return { purposeLI: purposeLI, vendorLI: vendorLI };
	}

	/**
	 * Build all derived consent artifacts once so callers can reuse them.
	 *
	 * `categoryConsent` is the readConsent() map: LI needs to know whether the
	 * visitor has acted (and what they granted) to read a legacy Reject All.
	 */
	function buildConsentArtifacts(purposeConsent, categoryConsent) {
		var li = buildLegitimateInterest(categoryConsent || {});
		return {
			vendorConsent: buildVendorConsent(),
			purposeLI:     li.purposeLI,
			vendorLI:      li.vendorLI
		};
	}

	/**
	 * Encode the DisclosedVendors segment from selected vendor IDs.
	 * SegmentType=1 (3 bits) + MaxVendorId (16 bits) + IsRangeEncoding=0 (1 bit) + bitfield
	 */
	function encodeDisclosedVendorsSegment() {
		if (SELECTED_VENDORS.length === 0) {
			return "IAAA"; // fallback: empty DV segment
		}

		var maxId = 0;
		for (var i = 0; i < SELECTED_VENDORS.length; i++) {
			if (SELECTED_VENDORS[i] > maxId) maxId = SELECTED_VENDORS[i];
		}

		var bits = [];
		pushBits(bits, 1, 3);      // SegmentType = 1 (DisclosedVendors)
		pushBits(bits, maxId, 16); // MaxVendorId
		pushBits(bits, 0, 1);      // IsRangeEncoding = 0 (bitfield)

		// Vendor bitfield: bit N = 1 if vendor N is disclosed.
		var vendorSet = {};
		for (var j = 0; j < SELECTED_VENDORS.length; j++) {
			vendorSet[SELECTED_VENDORS[j]] = true;
		}
		for (var n = 1; n <= maxId; n++) {
			pushBits(bits, vendorSet[n] ? 1 : 0, 1);
		}

		return bitsToBase64url(bits);
	}

	/**
	 * Encode the TC string (core segment + DisclosedVendors).
	 */
	function encodeTcString(purposeConsent, sfOptins, refreshLastUpdated, derived) {
		var bits = [];
		var artifacts      = derived || buildConsentArtifacts(purposeConsent, readConsent());
		var vendorConsent  = artifacts.vendorConsent;
		var purposeLI      = artifacts.purposeLI;
		var vendorLI       = artifacts.vendorLI;

		// Created and LastUpdated: deciseconds since the Unix epoch, and per the
		// IAB TC String format (Core String, "What happened to Created and
		// LastUpdated?") the two fields hold the SAME value, the UTC midnight
		// of the day the string was last updated. A sub-day timestamp is
		// fingerprinting entropy and a consent record names a day, not an
		// instant. An earlier version kept Created from the previous cookie
		// and wrote both at decisecond precision; every string failed the
		// precision rule and every re-consent failed the equality rule.
		var existingTimestamps = readTcTimestamps();
		var created;
		var lastUpdated;
		if (refreshLastUpdated || !existingTimestamps) {
			// A decision (or no prior string at all): this is the update.
			created = lastUpdated = utcMidnightDeciseconds(Date.now());
		} else {
			// A re-read is not an update, so the day is kept. A string written
			// by an earlier release with decisecond timestamps is brought to the
			// midnight of the day it was last updated, without claiming a new one.
			var kept = existingTimestamps.lastUpdated >= existingTimestamps.created
				? existingTimestamps.lastUpdated
				: existingTimestamps.created;
			created = lastUpdated = kept > 0
				? kept - (kept % DECISECONDS_PER_DAY)
				: utcMidnightDeciseconds(Date.now());
		}

		pushBits(bits, TCF_VERSION, 6);
		pushBits(bits, created, 36);
		pushBits(bits, lastUpdated, 36);
		pushBits(bits, CMP_ID, 12);
		pushBits(bits, CMP_VERSION, 12);
		pushBits(bits, 1, 6); // ConsentScreen
		var consentLang = cfg.consentLanguage || "EN";
		pushBits(bits, charTo6(consentLang.charAt(0)), 6);
		pushBits(bits, charTo6(consentLang.charAt(1)), 6);
		pushBits(bits, VENDOR_LIST, 12);
		pushBits(bits, TCF_POLICY_VERSION, 6);
		pushBits(bits, 1, 1); // IsServiceSpecific = true
		pushBits(bits, 0, 1); // UseNonStdTexts = false
		// SpecialFeatureOptIns - 12 bits
		for (var sf = 1; sf <= 12; sf++) {
			pushBits(bits, (sfOptins && sfOptins[String(sf)]) ? 1 : 0, 1);
		}

		// PurposesConsent - 24 bits
		for (var p = 1; p <= 24; p++) {
			pushBits(bits, purposeConsent[String(p)] ? 1 : 0, 1);
		}

		// PurposesLegitimateInterest - 24 bits
		for (var pl = 1; pl <= 24; pl++) {
			pushBits(bits, purposeLI[String(pl)] ? 1 : 0, 1);
		}

		// PurposeOneTreatment
		pushBits(bits, PURPOSE_ONE_TREATMENT ? 1 : 0, 1);

		// PublisherCC — "AA" is the spec's value for an unknown publisher
		// country; the server resolves the real one (resolve_tcf_publisher_cc).
		var publisherCC = cfg.publisherCC || "AA";
		pushBits(bits, charTo6(publisherCC.charAt(0)), 6);
		pushBits(bits, charTo6(publisherCC.charAt(1)), 6);

		// --- Vendor Consent Section ---
		var maxVendorConsentId = 0;
		for (var vc in vendorConsent) {
			if (vendorConsent.hasOwnProperty(vc)) {
				var vcId = parseInt(vc, 10);
				if (vcId > maxVendorConsentId) maxVendorConsentId = vcId;
			}
		}
		pushBits(bits, maxVendorConsentId, 16);
		pushBits(bits, 0, 1); // IsRangeEncoding = 0 (bitfield)
		for (var v1 = 1; v1 <= maxVendorConsentId; v1++) {
			pushBits(bits, vendorConsent[v1] ? 1 : 0, 1);
		}

		// --- Vendor LI Section ---
		var maxVendorLIId = 0;
		for (var vl in vendorLI) {
			if (vendorLI.hasOwnProperty(vl)) {
				var vlId = parseInt(vl, 10);
				if (vlId > maxVendorLIId) maxVendorLIId = vlId;
			}
		}
		pushBits(bits, maxVendorLIId, 16);
		pushBits(bits, 0, 1); // IsRangeEncoding = 0
		for (var v2 = 1; v2 <= maxVendorLIId; v2++) {
			pushBits(bits, vendorLI[v2] ? 1 : 0, 1);
		}

		// --- NumPubRestrictions = 0 ---
		pushBits(bits, 0, 12);

		var coreSegment = bitsToBase64url(bits);
		var dvSegment   = encodeDisclosedVendorsSegment();

		return coreSegment + "." + dvSegment;
	}

	/**
	 * Write the euconsent-v2 cookie (standard TCF cookie name).
	 */
	function setEuconsentCookie(tcString) {
		var expiry = 180; // days - matches FAZ consent cookie
		if (window._fazConfig && window._fazConfig._expiry) {
			expiry = window._fazConfig._expiry;
		}
		var date = new Date();
		date.setTime(date.getTime() + (expiry * 24 * 60 * 60 * 1000));
		var domain = "";
		if (window._fazConfig && window._fazConfig._rootDomain) {
			domain = ";domain=" + window._fazConfig._rootDomain;
		}
		var secure = location.protocol === "https:" ? ";Secure" : "";
		document.cookie = cookieName("euconsent-v2") + "=" + tcString + ";expires=" + date.toUTCString() + ";path=/" + domain + ";SameSite=Lax" + secure;
	}

	function clearEuconsentCookie() {
		var secure = location.protocol === "https:" ? ";Secure" : "";
		var expired = cookieName("euconsent-v2") + "=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/";
		document.cookie = expired + ";SameSite=Lax" + secure;
		if (window._fazConfig && window._fazConfig._rootDomain) {
			document.cookie = expired + ";domain=" + window._fazConfig._rootDomain + ";SameSite=Lax" + secure;
		}
	}

	function shouldPersistTcCookie(purposeConsent, vendorConsent, purposeLI, sfOptins) {
		for (var p = 2; p <= MAX_PURPOSE; p++) {
			if (purposeConsent[String(p)]) return true;
		}
		for (var vid in vendorConsent) {
			if (vendorConsent.hasOwnProperty(vid) && vendorConsent[vid]) return true;
		}
		for (var pli in purposeLI) {
			if (purposeLI.hasOwnProperty(pli) && purposeLI[pli]) return true;
		}
		for (var sf in (sfOptins || {})) {
			if ((sfOptins || {}).hasOwnProperty(sf) && sfOptins[sf]) return true;
		}
		return false;
	}

	/**
	 * Build the TCData object returned by getTCData / addEventListener.
	 */
	function buildTCData(purposeConsent, sfOptins, tcString, listenerIdVal, derived) {
		var artifacts     = derived || buildConsentArtifacts(purposeConsent, readConsent());
		var vendorConsent = artifacts.vendorConsent;
		var purposeLI     = artifacts.purposeLI;
		var vendorLI      = artifacts.vendorLI;

		// Build vendor consent/LI objects with string keys.
		var vcObj = {};
		var vlObj = {};
		for (var vc in vendorConsent) {
			if (vendorConsent.hasOwnProperty(vc)) vcObj[String(vc)] = vendorConsent[vc];
		}
		for (var vl in vendorLI) {
			if (vendorLI.hasOwnProperty(vl)) vlObj[String(vl)] = vendorLI[vl];
		}

		// Disclosed vendors.
		var disclosedObj = {};
		for (var d = 0; d < SELECTED_VENDORS.length; d++) {
			disclosedObj[String(SELECTED_VENDORS[d])] = true;
		}

		var data = {
			tcfPolicyVersion:    TCF_POLICY_VERSION,
			cmpId:               CMP_ID,
			cmpVersion:          CMP_VERSION,
			gvlVersion:          VENDOR_LIST,
			gdprApplies:         (typeof cfg.gdprApplies !== "undefined") ? !!cfg.gdprApplies : true,
			tcString:            tcString,
			listenerId:          listenerIdVal || undefined,
			eventStatus:         "tcloaded",
			cmpStatus:           cmpStatus,
			isServiceSpecific:   true,
			useNonStandardTexts: false,
			purposeOneTreatment: PURPOSE_ONE_TREATMENT,
			publisherCC:         cfg.publisherCC || "AA",
			outOfBand: {
				allowedVendors:   {},
				disclosedVendors: disclosedObj
			},
			purpose: {
				consents:            purposeConsent,
				legitimateInterests: purposeLI
			},
			vendor: {
				consents:            vcObj,
				legitimateInterests: vlObj
			},
			specialFeatureOptins: sfOptins || {},
			publisher: {
				consents:            {},
				legitimateInterests: {},
				customPurpose:       { consents: {}, legitimateInterests: {} },
				restrictions:        {}
			}
		};
		return data;
	}

	/**
	 * Notify all registered event listeners.
	 */
	function notifyListeners(eventStatus) {
		var consent  = readConsent();
		var purposes = buildPurposeConsent(consent);
		var sf       = buildSpecialFeatureOptins(consent);
		var derived  = buildConsentArtifacts(purposes, consent);
		var vendorConsent = derived.vendorConsent;
		var purposeLI = derived.purposeLI;
		var tcStr    = encodeTcString(purposes, sf, eventStatus === "useractioncomplete", derived);

		// Only write euconsent-v2 after user action, not during initial banner display.
		if (eventStatus === "useractioncomplete") {
			if (shouldPersistTcCookie(purposes, vendorConsent, purposeLI, sf)) {
				setEuconsentCookie(tcStr);
			} else {
				clearEuconsentCookie();
			}
		}

		for (var id in listeners) {
			if (!listeners.hasOwnProperty(id)) continue;
			var entry = listeners[id];
			var data  = buildTCData(purposes, sf, tcStr, parseInt(id, 10), derived);
			data.eventStatus = eventStatus || "tcloaded";
			try { entry.callback(data, true); } catch (_unused) { /* ignore listener error */ }
		}
	}

	/**
	 * The __tcfapi() - implements required TCF v2.3 commands.
	 */
	function tcfapi(command, version, callback, parameter) {
		if (typeof callback !== "function") return;

		var consent, purposes, tcStr, data;

		switch (command) {

			case "ping":
				callback({
					gdprApplies:       (typeof cfg.gdprApplies !== "undefined") ? !!cfg.gdprApplies : true,
					cmpLoaded:         cmpLoaded,
					cmpStatus:         cmpStatus,
					displayStatus:     displayOpen ? "visible" : "hidden",
					apiVersion:        "2.3",
					cmpVersion:        CMP_VERSION,
					cmpId:             CMP_ID,
					gvlVersion:        VENDOR_LIST,
					tcfPolicyVersion:  TCF_POLICY_VERSION
				}, true);
				break;

			case "getTCData": {
				consent  = readConsent();
				purposes = buildPurposeConsent(consent);
				var sfGet = buildSpecialFeatureOptins(consent);
				var derivedGet = buildConsentArtifacts(purposes, consent);
				tcStr    = encodeTcString(purposes, sfGet, false, derivedGet);
				data     = buildTCData(purposes, sfGet, tcStr, undefined, derivedGet);
				data.eventStatus = "tcloaded";
				callback(data, true);
				break;
			}

			case "addEventListener": {
				listenerId++;
				listeners[listenerId] = { callback: callback };
				consent  = readConsent();
				purposes = buildPurposeConsent(consent);
				var sfAdd = buildSpecialFeatureOptins(consent);
				var derivedAdd = buildConsentArtifacts(purposes, consent);
				tcStr    = encodeTcString(purposes, sfAdd, false, derivedAdd);
				data     = buildTCData(purposes, sfAdd, tcStr, listenerId, derivedAdd);
				// Registration reports `tcloaded`, not the current UI state.
				// Reporting cmpuishown here instead looked like the stricter
				// reading of the spec, and it is the wrong one for this API:
				// the events describe TRANSITIONS, and a listener added after
				// the banner was revealed genuinely missed that transition —
				// `ping` is the documented way to ask what the UI is doing
				// right now, and it answers correctly. Changing this broke
				// T03c and T09, which pin the registration status on purpose.
				data.eventStatus = "tcloaded";
				callback(data, true);
				break;
			}

			case "removeEventListener":
				if (parameter && listeners[parameter]) {
					delete listeners[parameter];
					callback(true);
				} else {
					callback(false);
				}
				break;

			case "getVendorList":
				var vendorListData = VENDORS && Object.keys(VENDORS).length > 0
					? {
						gvlSpecificationVersion: 3,
						vendorListVersion: VENDOR_LIST,
						tcfPolicyVersion: TCF_POLICY_VERSION,
						vendors: VENDORS,
						purposes: cfg.purposes || {},
						specialPurposes: cfg.specialPurposes || {},
						features: cfg.features || {},
						specialFeatures: cfg.specialFeatures || {}
					}
					: null;
				callback(vendorListData, !!vendorListData);
				break;

			default:
				callback(null, false);
		}
	}

	// Save queued commands before overwriting the stub.
	var rawQueue = (window.__tcfapi && window.__tcfapi.a) ? window.__tcfapi.a : [];
	var pendingQueue = Array.isArray(rawQueue) ? rawQueue.slice() : [];

	// Install the __tcfapi function
	window.__tcfapi = tcfapi;

	// Mark CMP as loaded BEFORE processing the queue so that
	// 'ping' commands executed from the queue see the correct status.
	cmpLoaded = true;
	cmpStatus = "loaded";

	// Process the command queue.
	//
	// The inline stub in class-frontend.php pushes its `arguments` object, as
	// the IAB reference stub does. That is array-like but NOT an Array, so an
	// Array.isArray() guard here dropped every command a vendor queued before
	// the CMP loaded: the callback never ran, the listener was never stored,
	// and the visitor's decision never reached that vendor. Accept any
	// array-like entry and copy it into a real array for the call.
	for (var q = 0; q < pendingQueue.length; q++) {
		var queued = pendingQueue[q];
		if (!queued || typeof queued !== "object" || typeof queued.length !== "number") continue;
		tcfapi.apply(null, Array.prototype.slice.call(queued));
	}

	// Create the __tcfapiLocator iframe (required by TCF spec)
	if (!window.frames["__tcfapiLocator"]) {
		var locatorFrame = document.createElement("iframe");
		locatorFrame.style.cssText = "display:none;position:absolute;width:0;height:0;";
		locatorFrame.name = "__tcfapiLocator";
		(document.body || document.documentElement).appendChild(locatorFrame);
	}

	// Handle postMessage-based cross-frame __tcfapi calls (TCF spec requirement).
	// The IAB TCF v2.x "__tcfapiLocator" protocol REQUIRES this listener to
	// accept messages from ANY origin: vendor tags embedded in cross-origin
	// iframes locate the CMP by posting an {__tcfapiCall} message and cannot be
	// origin-whitelisted without breaking the spec. The handler is safe by
	// construction — it ignores any payload lacking a well-formed __tcfapiCall,
	// only ever dispatches the (string) command/version to the controlled
	// tcfapi() router, and never reaches an eval/DOM/HTML sink. Origin
	// restriction is therefore intentionally not applied here.
	window.addEventListener("message", function (event) { // nosemgrep
		var json;
		try {
			json = typeof event.data === "string" ? JSON.parse(event.data) : event.data;
		} catch (_unused) {
			return;
		}
		if (!json || !json.__tcfapiCall) return;
		var call = json.__tcfapiCall;
		tcfapi(call.command, call.version, function (retValue, success) {
			var msg = {
				__tcfapiReturn: {
					returnValue: retValue,
					success:     success,
					callId:      call.callId
				}
			};
			if (event.source) {
				// Reply only to the exact origin that issued the __tcfapi call
				// instead of broadcasting with "*". Sandboxed/opaque-origin
				// frames report event.origin === "null" (a string); for those
				// we fall back to "*" because a targetOrigin of "null" would
				// never match and the response would be silently dropped.
				var replyOrigin =
					event.origin && event.origin !== "null" ? event.origin : "*";
				event.source.postMessage(
					typeof event.data === "string" ? JSON.stringify(msg) : msg,
					replyOrigin
				);
			}
		}, call.parameter);
	}, false);

	// Track banner visibility for ping displayStatus.
	//
	// `fazcookie_banner_loaded` is ONE-SHOT, and this file is enqueued with
	// script.js as a dependency: under ordered defer script.js finds the DOM
	// already parsed, so it can reveal the banner and fire the event during its
	// own execution, before this script has run at all. A listener registered
	// afterwards waits for an event that never comes again, and the CMP then
	// answers `hidden` for a banner the visitor is looking at, for the rest of
	// the page view — with no `cmpuishown` for any vendor. script.js records
	// window._fazBannerLoaded BEFORE dispatching, saying in its own comment
	// that it is there so late-running enhancers can recover the state; this is
	// that reader.
	function markBannerShown() {
		if (displayOpen) return;
		displayOpen = true;
		notifyListeners("cmpuishown");
	}
	document.addEventListener("fazcookie_banner_loaded", markBannerShown);
	if (true === window._fazBannerLoaded) {
		markBannerShown();
	}

	document.addEventListener("fazcookie_consent_update", function (event) {
		var action = event && event.detail ? event.detail.action : "";
		if (action === "init") return;
		displayOpen = false;
		notifyListeners("useractioncomplete");
	});

	// On page load, if user has previously given explicit consent, set euconsent-v2 cookie.
	// Check for action=yes in the consent cookie to ensure this was a real user action.
	function hasUserAction() {
		var pairs = readConsentCookiePairs();
		if (!pairs || isConsentCookieStale(pairs)) return false;
		return pairs.action === "yes" && pairs.undecided !== "1";
	}

	if (hasUserAction()) {
		var existingConsent = readConsent();
		var purposes = buildPurposeConsent(existingConsent);
		var sfInit   = buildSpecialFeatureOptins(existingConsent);
		var derivedInit = buildConsentArtifacts(purposes, existingConsent);
		var purposeLI = derivedInit.purposeLI;
		var vendorConsent = derivedInit.vendorConsent;
		if (shouldPersistTcCookie(purposes, vendorConsent, purposeLI, sfInit)) {
			var tcStr = encodeTcString(purposes, sfInit, false, derivedInit);
			setEuconsentCookie(tcStr);
		} else {
			clearEuconsentCookie();
		}
	}

})();
