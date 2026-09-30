import re, time, json, collections, urllib.request, sys

UA = 'Mozilla/5.0 (media-audit; contact: site maintainer)'
BASE = 'https://www.jerkovic.hr'

def get(url, timeout=25):
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept-Encoding': 'identity'})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read().decode('utf-8', 'replace')

urls = set([BASE + '/', BASE + '/?s=traktor', BASE + '/?s=sijacica', BASE + '/?s=horsch', BASE + '/feed/'])
# taxonomy + user sitemaps (skipped in the first crawl)
idx = get(BASE + '/wp-sitemap.xml')
for s in re.findall(r'<loc>([^<]+)</loc>', idx):
    if 'taxonom' in s or 'users' in s:
        try:
            urls.update(re.findall(r'<loc>([^<]+)</loc>', get(s)))
        except Exception as e:
            print('sitemap fail', s, e, file=sys.stderr)
# blog index ("novosti") and pagination, discovered from homepage/menu links
home = get(BASE + '/')
blog = set(re.findall(r'href="(https://www\.jerkovic\.hr/(?:novosti|category|tag|blog)[^"#]*)"', home))
urls.update(blog)
frontier = list(blog)
for b in list(blog):
    if re.search(r'/novosti/?$', b):
        for p in range(2, 8):
            urls.add(b.rstrip('/') + '/page/%d/' % p)
urls = sorted(urls)
print('archive urls:', len(urls), file=sys.stderr)

img = re.compile(r'uploads/((?:\d{4}/\d{2}/)[^\s"\'<>(),;]+?\.(?:jpe?g|png|webp|gif|avif))', re.I)
dim = re.compile(r'-(\d{2,4})x(\d{2,4})\.(?:jpe?g|png|webp|gif|avif)$', re.I)
by_dim = collections.Counter(); files = set(); ok = 0; fail = 0; per_url = {}
for u in urls:
    try:
        html = get(u)
    except Exception as e:
        fail += 1; continue
    ok += 1
    found = set(img.findall(html.replace('\\/', '/')))
    files |= found
    ds = collections.Counter()
    for f in found:
        m = dim.search(f)
        if m:
            d = '%sx%s' % m.groups(); by_dim[d] += 1; ds[d] += 1
    per_url[u] = len(found)
    time.sleep(0.3)

prev = {d for d, c, p in json.load(open('/Users/mirelaristic/Projects/jerkovic-media-cleanup/analysis/umc_crawl.json'))['by_dim']}
json.dump(sorted(files), open('/Users/mirelaristic/Projects/jerkovic-media-cleanup/analysis/umc_crawl_archives_files.json', 'w'))
print('fetched ok=%d fail=%d  distinct image files=%d' % (ok, fail, len(files)))
watch = {'845x321': 'entry_with_sidebar', '1210x423': 'entry_without_sidebar', '710x375': 'magazine', '1500x630': 'featured_large',
         '260x185': 'portfolio_small', '1500x430': 'featured', '495x400': 'portfolio', '845x684': 'gallery'}
for d, n in watch.items():
    print('  %-10s %-22s requested in archives: %d' % (d, n, by_dim.get(d, 0)))
w768 = sum(c for d, c in by_dim.items() if d.startswith('768x'))
w1536 = sum(c for d, c in by_dim.items() if d.startswith('1536x') or d.startswith('2048x'))
print('  768xN (medium_large):', w768, '   1536/2048:', w1536)
new = [(d, c) for d, c in by_dim.most_common() if d not in prev]
print('dimensions seen in archives but NOT in the first crawl:', new[:15])
print('top dims in archives:', by_dim.most_common(10))
blog_pages = [u for u in urls if '/novosti' in u or '/category/' in u or '/tag/' in u]
print('blog/category/tag pages fetched:', len(blog_pages), '; sample:', blog_pages[:4])
