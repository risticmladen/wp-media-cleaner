import re, time, json, collections, urllib.request, urllib.parse, sys

UA = 'Mozilla/5.0 (media-audit; contact: site maintainer)'
BASE = 'https://www.jerkovic.hr'

def get(url, timeout=25):
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept-Encoding': 'identity'})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read().decode('utf-8', 'replace')

# 1. collect URLs from sitemaps
urls = []
idx = get(BASE + '/wp-sitemap.xml')
subs = re.findall(r'<loc>([^<]+)</loc>', idx)
for s in subs:
    if 'users' in s or 'taxonom' in s:
        continue
    try:
        urls += re.findall(r'<loc>([^<]+)</loc>', get(s))
    except Exception as e:
        print('sitemap fail', s, e, file=sys.stderr)
urls = sorted(set(urls))
print('pages to crawl:', len(urls), file=sys.stderr)

img = re.compile(r'uploads/((?:\d{4}/\d{2}/)[^\s"\'<>(),;]+?\.(?:jpe?g|png|webp|gif|avif))', re.I)
dim = re.compile(r'-(\d{2,4})x(\d{2,4})\.(?:jpe?g|png|webp|gif|avif)$', re.I)

by_dim = collections.Counter(); pages_by_dim = collections.defaultdict(set)
full_files = set(); all_files = set(); errors = 0
per_page_sizes = {}
t0 = time.time()
for i, u in enumerate(urls):
    try:
        html = get(u)
    except Exception as e:
        errors += 1
        continue
    html = html.replace('\\/', '/')
    found = set(img.findall(html))
    for f in found:
        all_files.add(f)
        m = dim.search(f)
        if m:
            d = '%sx%s' % m.groups()
            by_dim[d] += 1
            pages_by_dim[d].add(u)
        else:
            full_files.add(f)
    time.sleep(0.3)
    if i % 50 == 0:
        print('  %d/%d  (%.0fs)' % (i, len(urls), time.time() - t0), file=sys.stderr)

out = {'pages': len(urls), 'errors': errors, 'distinct_files': len(all_files), 'no_dim_files': len(full_files),
       'by_dim': [(d, c, len(pages_by_dim[d])) for d, c in by_dim.most_common()]}
json.dump(out, open('/tmp/umc_crawl.json', 'w'))
json.dump(sorted(all_files), open('/tmp/umc_crawl_files.json', 'w'))
print('pages=%d errors=%d distinct image files=%d (without -WxH: %d)' % (len(urls), errors, len(all_files), len(full_files)))
print('dimension            requests  pages')
for d, c, p in out['by_dim'][:40]:
    print('%-18s %9d %6d' % (d, c, p))
print('distinct dims:', len(out['by_dim']))
