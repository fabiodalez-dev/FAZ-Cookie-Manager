/**
 * FAZ Cookie Manager — IAB TCF v2.2 CMP Stub
 *
 * Provides the window.__tcfapi() surface that ad-tech scripts expect.
 * Maps FAZ consent categories to TCF Purposes and generates a minimal
 * TC string (core segment only, no vendor consent).
 *
 * CMP ID 0 = unregistered / self-hosted.
 * For full TCF compliance users must register with IAB as a CMP.
 */
(function () {
	"use strict";

	var CMP_ID      = 0;
	var CMP_VERSION  = 1;
	var TCF_VERSION  = 2;
	var VENDOR_LIST  = 0;  // no vendor list loaded
	var MAX_PURPOSE  = 10; // TCF v2.2 has 10 standard purposes

	// Map FAZ category slugs → TCF Purpose IDs
	// Purpose 1: Store/access info on device
	// Purpose 2: Select basic ads
	// Purpose 3: Create personalised ads profile
	// Purpose 4: Select personalised ads
	// Purpose 5: Create personalised content profile
	// Purpose 6: Select personalised content
	// Purpose 7: Measure ad performance
	// Purpose 8: Measure content performance
	// Purpose 9: Apply market research to generate audience insights
	// Purpose 10: Develop and improve products
	var CATEGORY_TO_PURPOSES = {
		necessary:     [1],
		functional:    [5, 6],
		analytics:     [7, 8, 9, 10],
		performance:   [8, 9],
		advertisement: [2, 3, 4, 7]
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
					key === "performance" || key === "advertisement") {
					consent[key] = val === "yes";
				}
			}
		}
		return consent;
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
		// Purpose 1 (store/access) — always granted if necessary
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
	 * Encode a minimal TC string (core segment only).
	 *
	 * The TC string is a base64url-encoded bitfield.  We implement just
	 * enough for the core segment so that callers that inspect the string
	 * (e.g. Google) can derive purpose consent.
	 *
	 * Core segment layout (simplified — we omit vendor consent):
	 *   Version            6 bits
	 *   Created            36 bits (deciseconds since 2020-01-01)
	 *   LastUpdated        36 bits
	 *   CmpId              12 bits
	 *   CmpVersion         12 bits
	 *   ConsentScreen       6 bits
	 *   ConsentLanguage    12 bits (2 chars × 6 bits)
	 *   VendorListVersion  12 bits
	 *   TcfPolicyVersion    6 bits
	 *   IsServiceSpecific   1 bit  = 1 (site-specific)
	 *   UseNonStdTexts      1 bit  = 0
	 *   SpecialFeatureOptIns 12 bits = 0
	 *   PurposesConsent     24 bits
	 *   PurposesLI          24 bits = 0
	 *   PurposeOneTreatment  1 bit = 0
	 *   PublisherCC         12 bits
	 *   MaxVendorConsentId 16 bits = 0
	 *   EncodingType        1 bit  = 0
	 *   --- total: ~210 bits ≈ 27 bytes
	 */
	function encodeTcString(purposeConsent) {
		var bits = [];

		function pushBits(value, length) {
			var s = (value >>> 0).toString(2);
			while (s.length < length) s = "0" + s;
			s = s.substring(s.length - length);
			for (var k = 0; k < length; k++) bits.push(s.charAt(k) === "1" ? 1 : 0);
		}

		function charTo6(c) {
			return c.toUpperCase().charCodeAt(0) - 65; // A=0, B=1, ...
		}

		var now      = Math.round(Date.now() / 100); // deciseconds
		var epoch    = Math.round(Date.UTC(2020, 0, 1) / 100);
		var created  = now - epoch;

		pushBits(TCF_VERSION, 6);       // Version
		pushBits(created, 36);          // Created
		pushBits(created, 36);          // LastUpdated
		pushBits(CMP_ID, 12);           // CmpId
		pushBits(CMP_VERSION, 12);      // CmpVersion
		pushBits(1, 6);                 // ConsentScreen
		pushBits(charTo6("E"), 6);      // ConsentLanguage char 1
		pushBits(charTo6("N"), 6);      // ConsentLanguage char 2
		pushBits(VENDOR_LIST, 12);      // VendorListVersion
		pushBits(4, 6);                 // TcfPolicyVersion (v2.2 = 4)
		pushBits(1, 1);                 // IsServiceSpecific = true
		pushBits(0, 1);                 // UseNonStdTexts = false
		pushBits(0, 12);               // SpecialFeatureOptIns

		// PurposesConsent — 24 bits (purposes 1–24, we use 1–10)
		for (var p = 1; p <= 24; p++) {
			pushBits(purposeConsent[String(p)] ? 1 : 0, 1);
		}

		// PurposesLegitimateInterest — 24 bits (all 0)
		pushBits(0, 24);

		// PurposeOneTreatment
		pushBits(0, 1);

		// PublisherCC (2 chars)
		pushBits(charTo6("I"), 6);  // IT by default
		pushBits(charTo6("T"), 6);

		// MaxVendorConsentId = 0, EncodingType = 0
		pushBits(0, 16);
		pushBits(0, 1);

		// Pad to multiple of 6 for base64
		while (bits.length % 6 !== 0) bits.push(0);

		// Convert to base64url
		var base64Chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_";
		var str = "";
		for (var i = 0; i < bits.length; i += 6) {
			var val = 0;
			for (var b = 0; b < 6; b++) {
				val = (val << 1) | (bits[i + b] || 0);
			}
			str += base64Chars.charAt(val);
		}

		return str;
	}

	/**
	 * Build the TCData object returned by getTCData / addEventListener.
	 */
	function buildTCData(purposeConsent, tcString, listenerIdVal) {
		var data = {
			tcfPolicyVersion:  4,
			cmpId:             CMP_ID,
			cmpVersion:        CMP_VERSION,
			gdprApplies:       true,
			tcString:          tcString,
			listenerId:        listenerIdVal || undefined,
			eventStatus:       "tcloaded",
			cmpStatus:         "loaded",
			isServiceSpecific: true,
			useNonStandardTexts: false,
			purposeOneTreatment: false,
			publisherCC:       "IT",
			outOfBand: {
				allowedVendors:  {},
				disclosedVendors: {}
			},
			purpose: {
				consents:           purposeConsent,
				legitimateInterests: {}
			},
			vendor: {
				consents:           {},
				legitimateInterests: {}
			},
			specialFeatureOptins: {},
			publisher: {
				consents:           {},
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
		var tcStr    = encodeTcString(purposes);

		for (var id in listeners) {
			if (!listeners.hasOwnProperty(id)) continue;
			var entry = listeners[id];
			var data  = buildTCData(purposes, tcStr, parseInt(id, 10));
			data.eventStatus = eventStatus || "tcloaded";
			try { entry.callback(data, true); } catch (_unused) { /* ignore listener error */ }
		}
	}

	/**
	 * The __tcfapi() stub — implements required TCF v2.2 commands.
	 */
	function tcfapi(command, version, callback, parameter) {
		if (typeof callback !== "function") return;

		var consent, purposes, tcStr, data;

		switch (command) {

			case "ping":
				callback({
					gdprApplies:       true,
					cmpLoaded:         cmpLoaded,
					cmpStatus:         "loaded",
					displayStatus:     displayOpen ? "visible" : "hidden",
					apiVersion:        "2.2",
					cmpVersion:        CMP_VERSION,
					cmpId:             CMP_ID,
					gvlVersion:        VENDOR_LIST,
					tcfPolicyVersion:  4
				}, true);
				break;

			case "getTCData":
				consent  = readConsent();
				purposes = buildPurposeConsent(consent);
				tcStr    = encodeTcString(purposes);
				data     = buildTCData(purposes, tcStr);
				data.eventStatus = "tcloaded";
				callback(data, true);
				break;

			case "addEventListener":
				listenerId++;
				listeners[listenerId] = { callback: callback };
				// Immediately fire with current state
				consent  = readConsent();
				purposes = buildPurposeConsent(consent);
				tcStr    = encodeTcString(purposes);
				data     = buildTCData(purposes, tcStr, listenerId);
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

			default:
				callback(null, false);
		}
	}

	// Save queued commands before overwriting the stub.
	var rawQueue = (window.__tcfapi && window.__tcfapi.a) ? window.__tcfapi.a : [];
	var pendingQueue = Array.isArray(rawQueue) ? rawQueue.slice() : [];

	// Install the __tcfapi function
	window.__tcfapi = tcfapi;

	// Process the command queue if any scripts called __tcfapi before we loaded
	for (var q = 0; q < pendingQueue.length; q++) {
		if (Array.isArray(pendingQueue[q])) {
			tcfapi.apply(null, pendingQueue[q]);
		}
	}

	// Create the __tcfapiLocator iframe (required by TCF spec for cross-frame communication)
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

	// Listen for FAZ consent changes and re-notify TCF listeners
	document.addEventListener("fazcookie_consent_update", function () {
		notifyListeners("useractioncomplete");
	});

	// Track banner visibility for ping displayStatus
	document.addEventListener("fazcookie_banner_loaded", function () {
		displayOpen = true;
		notifyListeners("cmpuishown");
	});

	document.addEventListener("fazcookie_consent_update", function () {
		displayOpen = false;
	});

})();
