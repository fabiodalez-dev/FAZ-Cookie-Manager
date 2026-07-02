#!/usr/bin/env python3
"""
Compliance audit of every geo-routing ruleset (legislation).

Goes beyond schema validation (scripts/validate-rulesets.sh): it checks the
*legal semantics* of each ruleset against the consent model it declares.

Invariants checked per legislation:
  N1  necessary == granted-locked (never deniable)             [ALL]
  O1  opt-in: analytics/marketing/profiling default == denied  [opt-in]
      (ePrivacy/GDPR prior-blocking: no non-essential cookie before consent)
  O2  opt-in: Consent Mode v2 ad_storage/analytics_storage denied
  O3  opt-in: equal_weight_buttons == true (EDPB no-dark-pattern)
  U1  opt-out / sensitive-opt-in: profiling default is gated
      (denied or denied-until-action — sensitive data needs opt-in)
  U2  US opt-out states: gpc_honored == true (universal opt-out mandate)
  U3  US sale/share states: donotsell_link_required == true
  G1  gpc_required implies gpc_honored (cannot require what you don't honour)
  M1  declared model is one of the schema enum values

Exit 0 = no ERROR findings (WARN allowed); 1 = at least one ERROR.
"""
import json, glob, os, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RDIR = os.path.join(ROOT, 'admin/modules/geo-routing/rulesets')

# US states with a statutory universal opt-out / GPC expectation.
US_OPTOUT_GPC = {
    'ccpa-california','cpa-colorado','ctdpa-connecticut','tdpsa-texas',
    'njdpl-newjersey','mcdpa-minnesota','modpa-maryland','nhpl-newhampshire',
    'ocpa-oregon','delaware-dpdpa','mcdpa-montana',
}
NONNEC = ['functional','analytics','marketing','profiling']
MODELS = {'opt-in','opt-out','hybrid','opt-out-with-sensitive-opt-in'}

errors=[]; warns=[]; rows=[]

def add(level,rid,msg):
    (errors if level=='ERROR' else warns).append(f'[{level}] {rid}: {msg}')

files=sorted(f for f in glob.glob(os.path.join(RDIR,'*.json'))
            if not os.path.basename(f).startswith('_'))

for f in files:
    d=json.load(open(f))
    rid=d.get('id','?')
    model=d.get('model','?')
    sig=d.get('signals',{})
    ui=d.get('ui',{})
    dc=ui.get('default_categories',{})
    cmv2=sig.get('cmv2',{})
    gpc_h=sig.get('gpc_honored'); gpc_r=sig.get('gpc_required')
    rows.append((rid,model,dc.get('necessary'),dc.get('functional'),
                 dc.get('analytics'),dc.get('marketing'),dc.get('profiling'),
                 'Y' if gpc_h else '-', 'Y' if gpc_r else '-',
                 'Y' if ui.get('equal_weight_buttons') else '-',
                 'Y' if ui.get('donotsell_link_required') else '-'))

    # M1
    if model not in MODELS:
        add('ERROR',rid,f'unknown model "{model}"')
    # N1
    if dc.get('necessary')!='granted-locked':
        add('ERROR',rid,f'necessary must be granted-locked, got "{dc.get("necessary")}"')
    # G1
    if gpc_r and not gpc_h:
        add('ERROR',rid,'gpc_required=true but gpc_honored=false')

    if model=='opt-in':
        # O1 prior blocking
        for c in NONNEC:
            v=dc.get(c)
            if v not in ('denied','denied-until-action'):
                add('ERROR',rid,f'opt-in: category "{c}" defaults to "{v}" (must be denied — no prior consent)')
        # O2 consent mode
        for k in ('ad_storage','analytics_storage','ad_user_data','ad_personalization'):
            if cmv2.get(k) not in ('denied','denied-until-action'):
                add('ERROR',rid,f'opt-in: cmv2.{k}="{cmv2.get(k)}" (must be denied)')
        # O3 buttons
        if ui.get('equal_weight_buttons') is not True:
            add('WARN',rid,'opt-in: equal_weight_buttons not true (dark-pattern risk)')

    elif model in ('opt-out','opt-out-with-sensitive-opt-in','hybrid'):
        # U1 sensitive gating
        if dc.get('profiling') not in ('denied','denied-until-action'):
            add('WARN',rid,f'profiling defaults to "{dc.get("profiling")}" (sensitive data usually opt-in)')
        # U2/U3 for US opt-out GPC states
        if rid in US_OPTOUT_GPC:
            if gpc_h is not True:
                add('ERROR',rid,'US opt-out state must honour GPC (gpc_honored=true)')
            if ui.get('donotsell_link_required') is not True:
                add('WARN',rid,'US sale/share state: donotsell_link_required expected true')

# ---- matrix ----
hdr=('id','model','nec','func','anal','mkt','prof','gpcH','gpcR','eqBtn','dns')
w=[max(len(str(r[i])) for r in rows+[hdr]) for i in range(len(hdr))]
def line(r): return '  '.join(str(c).ljust(w[i]) for i,c in enumerate(r))
print('\n== COMPLIANCE MATRIX — %d legislations ==\n'%len(rows))
print(line(hdr)); print('  '.join('-'*w[i] for i in range(len(hdr))))
for r in sorted(rows): print(line(r))

print('\n== FINDINGS ==')
if not errors and not warns:
    print('  No findings — all legislations conform to model invariants.')
for e in errors: print('  '+e)
for x in warns: print('  '+x)
print('\nLegislations: %d | ERROR: %d | WARN: %d'%(len(rows),len(errors),len(warns)))
sys.exit(1 if errors else 0)
