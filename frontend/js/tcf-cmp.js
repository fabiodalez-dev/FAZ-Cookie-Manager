/**
 * FAZ Cookie Manager - IAB TCF v2.3 CMP
 *
 * Provides the window.__tcfapi() surface that ad-tech scripts expect.
 * Maps FAZ consent categories to TCF Purposes and generates a TC string
 * with core segment + mandatory DisclosedVendors segment (TCF v2.3).
 *
 * When GVL data is provided via _fazTcfConfig, encodes real vendor consent
 * and legitimate interest bitfields based on user category consent.
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
	var CATEGORY_TO_PURPOSES = {
		necessary:     [1],
		functional:    [5, 6, 11],
		analytics:     [8, 9, 10],
		performance:   [8, 9],
		marketing:     [2, 3, 4, 7]
	};

	// Event listeners
	var listeners   = {};
	var listenerId  = 0;
	var cmpLoaded   = true;
	var displayOpen = false;

	/**
	 * Read the FAZ consent cookie and return a category→boolean map.
	 */
	function readConsent() {
		var consent = { necessary: true };
		var match   = document.cookie.match(/fazcookie-consent=([^;]+)/);
		if (!match) return consent;

		var pairs = match[1].split(",");
		for (var i = 0; i < pairs.length; i++) {
			var kv = pairs[i].split(":");
			if (kv.length === 2) {
				var key = kv[0].trim();
				var val = kv[1].trim();
				if (key === "necessary" || key === "functional" || key === "analytics" ||
					key === "performance" || key === "marketing" || key === "advertisement") {
					consent[key === "advertisement" ? "marketing" : key] = val === "yes";
				}
			}
		}
		return consent;
	}

	/**
	 * Read vendor-level consent from cookie (if present).
	 * Format: fazVendorConsent=1:yes,2:no,5:yes
	 */
	function readVendorConsent() {
		var vendorConsent = {};
		var match = document.cookie.match(/fazVendorConsent=([^;]+)/);
		if (!match) return vendorConsent;

		var pairs = match[1].split(",");
		for (var i = 0; i < pairs.length; i++) {
			var kv = pairs[i].split(":");
			if (kv.length === 2) {
				vendorConsent[parseInt(kv[0], 10)] = kv[1].trim() === "yes";
			}
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
		purposes["1"] = !!categoryConsent.necessary;

		for (var cat in CATEGORY_TO_PURPOSES) {
			if (!CATEGORY_TO_PURPOSES.hasOwnProperty(cat)) continue;
			if (categoryConsent[cat]) {
				var ids = CATEGORY_TO_PURPOSES[cat];
				for (var j = 0; j < ids.length; j++) {
					purposes[String(ids[j])] = true;
				}
			}
		}
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
	 * Build vendor consent map.
	 * A vendor gets consent=true if:
	 *  - Explicit vendor consent cookie exists → use it
	 *  - Otherwise: user consented to ALL purposes that vendor declares under consent basis
	 */
	function buildVendorConsent(purposeConsent) {
		var vendorConsent = {};
		var explicit = readVendorConsent();

		for (var i = 0; i < SELECTED_VENDORS.length; i++) {
			var vid = SELECTED_VENDORS[i];
			var v = VENDORS[vid];

			// Check explicit vendor-level consent first.
			if (typeof explicit[vid] !== "undefined") {
				vendorConsent[vid] = explicit[vid];
				continue;
			}

			// Derive from purpose consent: vendor gets consent if ALL its
			// consent-basis purposes are consented.
			if (!v || !v.purposes || v.purposes.length === 0) {
				vendorConsent[vid] = false;
				continue;
			}
			var allConsented = true;
			for (var j = 0; j < v.purposes.length; j++) {
				if (!purposeConsent[String(v.purposes[j])]) {
					allConsented = false;
					break;
				}
			}
			vendorConsent[vid] = allConsented;
		}
		return vendorConsent;
	}

	/**
	 * Build PurposesLegitimateInterest bitfield.
	 * Per IAB TCF spec: LI bit = true only if a vendor declares LI for that purpose
	 * AND the user has NOT exercised their Right to Object (i.e. user has consented
	 * to the corresponding category).
	 */
	function buildPurposeLI(purposeConsent) {
		var li = {};
		for (var p = 1; p <= 24; p++) li[String(p)] = false;

		for (var i = 0; i < SELECTED_VENDORS.length; i++) {
			var v = VENDORS[SELECTED_VENDORS[i]];
			if (!v || !v.legIntPurposes) continue;
			for (var j = 0; j < v.legIntPurposes.length; j++) {
				var pid = String(v.legIntPurposes[j]);
				// Only set LI if user has not objected (consent for this purpose exists).
				if (purposeConsent[pid]) {
					li[pid] = true;
				}
			}
		}
		return li;
	}

	/**
	 * Build vendor legitimate interest map.
	 * Per IAB TCF spec: vendor LI = true only if at least one of the vendor's
	 * LI purposes still has LI established (user did not object).
	 */
	function buildVendorLI(purposeLI) {
		var vendorLI = {};
		for (var i = 0; i < SELECTED_VENDORS.length; i++) {
			var vid = SELECTED_VENDORS[i];
			var v = VENDORS[vid];
			if (!v || !v.legIntPurposes || v.legIntPurposes.length === 0) {
				vendorLI[vid] = false;
				continue;
			}
			var hasAllowedLI = false;
			for (var j = 0; j < v.legIntPurposes.length; j++) {
				if (purposeLI[String(v.legIntPurposes[j])]) {
					hasAllowedLI = true;
					break;
				}
			}
			vendorLI[vid] = hasAllowedLI;
		}
		return vendorLI;
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
	function encodeTcString(purposeConsent, sfOptins) {
		var bits = [];
		var vendorConsent = buildVendorConsent(purposeConsent);
		var purposeLI     = buildPurposeLI(purposeConsent);
		var vendorLI      = buildVendorLI(purposeLI);

		// Deciseconds since Unix epoch (Jan 1, 1970) per IAB TCF spec.
		var created = Math.round(Date.now() / 100);

		pushBits(bits, TCF_VERSION, 6);
		pushBits(bits, created, 36);
		pushBits(bits, created, 36);
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

		// PublisherCC
		var publisherCC = cfg.publisherCC || "IT";
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
		document.cookie = "euconsent-v2=" + tcString + ";expires=" + date.toUTCString() + ";path=/" + domain + ";SameSite=Lax" + secure;
	}

	/**
	 * Build the TCData object returned by getTCData / addEventListener.
	 */
	function buildTCData(purposeConsent, sfOptins, tcString, listenerIdVal) {
		var vendorConsent = buildVendorConsent(purposeConsent);
		var purposeLI     = buildPurposeLI(purposeConsent);
		var vendorLI      = buildVendorLI(purposeLI);

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
			cmpStatus:           "loaded",
			isServiceSpecific:   true,
			useNonStandardTexts: false,
			purposeOneTreatment: PURPOSE_ONE_TREATMENT,
			publisherCC:         cfg.publisherCC || "IT",
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
		var tcStr    = encodeTcString(purposes, sf);

		// Only write euconsent-v2 after user action, not during initial banner display.
		if (eventStatus === "useractioncomplete") {
			setEuconsentCookie(tcStr);
		}

		for (var id in listeners) {
			if (!listeners.hasOwnProperty(id)) continue;
			var entry = listeners[id];
			var data  = buildTCData(purposes, sf, tcStr, parseInt(id, 10));
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
					cmpStatus:         "loaded",
					displayStatus:     displayOpen ? "visible" : "hidden",
					apiVersion:        "2.3",
					cmpVersion:        CMP_VERSION,
					cmpId:             CMP_ID,
					gvlVersion:        VENDOR_LIST,
					tcfPolicyVersion:  TCF_POLICY_VERSION
				}, true);
				break;

			case "getTCData":
				consent  = readConsent();
				purposes = buildPurposeConsent(consent);
				var sfGet = buildSpecialFeatureOptins(consent);
				tcStr    = encodeTcString(purposes, sfGet);
				data     = buildTCData(purposes, sfGet, tcStr);
				data.eventStatus = "tcloaded";
				callback(data, true);
				break;

			case "addEventListener":
				listenerId++;
				listeners[listenerId] = { callback: callback };
				consent  = readConsent();
				purposes = buildPurposeConsent(consent);
				var sfAdd = buildSpecialFeatureOptins(consent);
				tcStr    = encodeTcString(purposes, sfAdd);
				data     = buildTCData(purposes, sfAdd, tcStr, listenerId);
				data.eventStatus = "tcloaded";
				callback(data, true);
				break;

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

	// Process the command queue
	for (var q = 0; q < pendingQueue.length; q++) {
		if (Array.isArray(pendingQueue[q])) {
			tcfapi.apply(null, pendingQueue[q]);
		}
	}

	// Create the __tcfapiLocator iframe (required by TCF spec)
	if (!window.frames["__tcfapiLocator"]) {
		var locatorFrame = document.createElement("iframe");
		locatorFrame.style.cssText = "display:none;position:absolute;width:0;height:0;";
		locatorFrame.name = "__tcfapiLocator";
		(document.body || document.documentElement).appendChild(locatorFrame);
	}

	// Handle postMessage-based cross-frame __tcfapi calls (TCF spec requirement)
	window.addEventListener("message", function (event) {
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
				event.source.postMessage(
					typeof event.data === "string" ? JSON.stringify(msg) : msg,
					"*"
				);
			}
		}, call.parameter);
	}, false);

	// Track banner visibility for ping displayStatus.
	document.addEventListener("fazcookie_banner_loaded", function () {
		displayOpen = true;
		notifyListeners("cmpuishown");
	});

	document.addEventListener("fazcookie_consent_update", function (event) {
		var action = event && event.detail ? event.detail.action : "";
		if (action === "init") return;
		displayOpen = false;
		notifyListeners("useractioncomplete");
	});

	// On page load, if user has previously given explicit consent, set euconsent-v2 cookie.
	// Check for action=yes in the consent cookie to ensure this was a real user action.
	function hasUserAction() {
		var match = document.cookie.match(/fazcookie-consent=([^;]+)/);
		if (!match) return false;
		var pairs = match[1].split(",");
		for (var i = 0; i < pairs.length; i++) {
			var kv = pairs[i].split(":");
			if (kv.length === 2 && kv[0].trim() === "action") {
				return kv[1].trim() === "yes";
			}
		}
		return false;
	}

	if (hasUserAction()) {
		var existingConsent = readConsent();
		var purposes = buildPurposeConsent(existingConsent);
		var sfInit   = buildSpecialFeatureOptins(existingConsent);
		var tcStr    = encodeTcString(purposes, sfInit);
		setEuconsentCookie(tcStr);
	}

})();
