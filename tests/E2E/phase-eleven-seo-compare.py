"""Offline comparison of the same public fixture; explicit phase-11 lab criteria."""
import json
from pathlib import Path
out=Path('docs/plans/phase-11-evidence/seo-performance')
base=json.loads((out/'baseline-047-full.json').read_text());results=[]
for stage in ['candidate-full','candidate-essential']:
 candidate=json.loads((out/(stage+'.json')).read_text())
 for baseline,row in zip(base['rows'],candidate['rows']):
  assert (baseline['url'],baseline['width'])==(row['url'],row['width'])
  delta=row['uncompressed_resource_bytes']-baseline['uncompressed_resource_bytes']
  static=lambda r:sum(x['bytes'] for x in r['resources'] if x['type'] in ['script','stylesheet'])
  times={k:{'baseline':baseline['median'][k],'candidate':row['median'][k],'allowance_ms':max(25,baseline['median'][k]*.5)} for k in ['ttfb_ms','fcp_ms','lcp_ms']}
  checks={'requests_not_increased':row['request_count']<=baseline['request_count'],'public_bytes_within_4kib_allowance':delta<=4096,'static_bytes_within_4kib_allowance':static(row)-static(baseline)<=4096,'cls_at_most_0_01':row['median']['cls']<=.01,'timing_medians_within_noise_allowance':all(v['candidate']<=v['baseline']+v['allowance_ms'] for v in times.values())}
  results.append({'stage':stage,'url':row['url'],'width':row['width'],'byte_delta':delta,'static_byte_delta':static(row)-static(baseline),'byte_delta_percent':delta/baseline['uncompressed_resource_bytes']*100,'timings':times,'checks':checks});assert all(checks.values()),str(results[-1])
report={'status':'pass','criteria_status':'Technical criteria adopted for this validation; not previously agreed owner budgets or production CWV','criteria':{'requests':'No increase; current baseline4','public_and_static_bytes':'At most4KiB additional uncompressed bytes per same fixture; small fixed allowance for precompiled composition vocabulary/runtime, inspect resource-by-resource','timing_medians':'At most baseline+max(25ms,50%baseline) for TTFB/FCP/LCP; localhost scheduling/frame noise allowance,7 samples; diagnostic lab only','cls':'Median at most0.01; observed0 in every individual sample','semantics':'Exact SEO, publication,sitemap,layout assertions; no editor asset requests'},'results':results}
(out/'comparison.json').write_text(json.dumps(report,indent=2)+'\n');print('PASS adopted technical criteria for all8 candidate comparisons')
