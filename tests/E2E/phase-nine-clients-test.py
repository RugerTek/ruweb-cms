"""Secret-free evidence regressions; tool text JSON and non-secret artifact hashes differ."""
import importlib.util,json,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('clients',Path(__file__).with_name('phase-nine-clients.py'));clients=importlib.util.module_from_spec(spec);spec.loader.exec_module(clients)
class EvidenceRedaction(unittest.TestCase):
 def test_php_slash_escaped_tool_content_redacts_preview_urls_and_link_token(self):
  token='a'*64;url='http://127.0.0.1:8022/draft-preview/'+token
  payload={'data':{'link':{'token':token,'id':1},'preview_url':url,'url':url,'revision':'b'*64}}
  evidence={'result':{'content':[{'type':'text','text':json.dumps(payload).replace('/','\\/')}]}}
  clean=clients.sanitize(evidence,[]);serialized=json.dumps(clean)
  self.assertNotIn(token,serialized);self.assertNotIn('/draft-preview/',serialized)
  parsed=json.loads(clean['result']['content'][0]['text']);self.assertEqual(parsed['data']['revision'],'b'*64);self.assertEqual(parsed['data']['link']['id'],1)
 def test_native_credentials_and_plain_preview_diagnostics_are_redacted(self):
  clean=clients.sanitize('ruweb_mcp_fixture-secret http://127.0.0.1:8022/draft-preview/abc123 /draft-preview/abc123',['private fixture secret'])
  self.assertNotIn('ruweb_mcp_',clean);self.assertNotIn('abc123',clean)
 def test_nonsecret_token_named_source_paths_keep_checksum_identity(self):
  hashes={'app/Models/OauthAccessToken.php':'c'*64,'app/Services/Security/PasswordRecovery.php':'d'*64,'tests/E2E/phase-nine-clients.py':'e'*64}
  self.assertEqual(clients.sanitize(hashes,[]),hashes)
if __name__=='__main__':unittest.main()
