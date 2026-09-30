import os, re, json, collections, subprocess

UP = '/Users/mirelaristic/Projects/jerkovic/wp-content/uploads'
crawl = json.load(open('/tmp/umc_crawl.json'))
crawl_files = set(json.load(open('/tmp/umc_crawl_files.json')))
seen = {d: c for d, c, p in crawl['by_dim']}

print('--- registered Enfold/WP sizes: requested in live HTML (srcset included)?')
hard = {'featured 1500x430': '1500x430', 'featured_large 1500x630': '1500x630', 'entry_without_sidebar 1210x423': '1210x423',
        'gallery 845x684': '845x684', 'entry_with_sidebar 845x321': '845x321', 'magazine 710x375': '710x375',
        'portfolio 495x400': '495x400', 'portfolio_small 260x185': '260x185', 'square/thumbnail 180x180': '180x180',
        'shop_thumbnail 120x120': '120x120', 'shop_catalog 450x450': '450x450', 'widget 36x36': '36x36'}
for k, d in hard.items():
    print('  %-34s %s' % (k, seen.get(d, 0)))
soft = {'extra_large (1500 wide, soft)': 1500, 'masonry (705 wide, soft)': 705, 'shop_single (450 wide, soft)': 450,
        'WP 2048': 2048, 'WP 1536': 1536, 'large (1030 wide)': 1030, 'medium_large (768 wide)': 768, 'medium (300 wide)': 300}
for k, w in soft.items():
    n = sum(c for d, c, p in crawl['by_dim'] if d.startswith('%dx' % w) or d.endswith('x%d' % w))
    print('  %-34s %s' % (k, n))

# DB text, to protect explicit references to specific size files
def q(sql):
    r = subprocess.run(['ddev', 'wp', 'db', 'query', sql, '--skip-column-names', '--batch', '--skip-themes', '--skip-plugins'],
                       cwd='/Users/mirelaristic/Projects/jerkovic', capture_output=True, text=True)
    return r.stdout
db_text = q("SELECT post_content FROM wp_posts WHERE post_type NOT IN ('revision','attachment') AND post_status<>'trash'") + \
          q("SELECT meta_value FROM wp_postmeta WHERE meta_key NOT IN ('_wp_attachment_metadata','_wp_attached_file')") + \
          q("SELECT option_value FROM wp_options WHERE option_name NOT LIKE '\\_transient%'") + \
          q("SELECT * FROM wp_layerslider")
db_text = db_text.replace('\\/', '/').replace('\\\\', '')
urlre = re.compile(r'uploads/(\d{4}/\d{2}/[^\s"\'<>(),;\\]+?\.(?:jpe?g|png|webp|gif|avif))', re.I)
db_files = set(urlre.findall(db_text))
print('\nexact uploads files referenced in DB text: %d ; in live HTML: %d' % (len(db_files), len(crawl_files)))
protected = db_files | crawl_files

drop_hard = {'1500x630', '1210x423', '845x321', '710x375', '260x185'}
drop_w = {2048, 1536}           # WP core extra sizes
drop_shop = set()               # keep 120/450 for now
sufre = re.compile(r'-(\d{2,4})x(\d{2,4})\.(jpe?g|png|webp|gif|avif)$', re.I)
tot = collections.Counter(); byd = collections.Counter(); prot_n = 0; kept_b = 0
allsize_b = 0
for dp, dn, fn in os.walk(UP):
    rel_dir = os.path.relpath(dp, UP)
    if not re.match(r'^\d{4}', rel_dir): continue
    for f in fn:
        m = sufre.search(f)
        if not m: continue
        w, h = int(m.group(1)), int(m.group(2))
        s = os.path.getsize(os.path.join(dp, f)); allsize_b += s
        d = '%dx%d' % (w, h)
        is_drop = d in drop_hard or w in drop_w
        if not is_drop: continue
        rel = rel_dir.replace(os.sep, '/') + '/' + f
        if rel in protected:
            prot_n += 1; kept_b += s; continue
        tot['files'] += 1; tot['bytes'] += s
        byd['1500xN(extra_large)' if w == 1500 and d != '1500x630' and d != '1500x430' else ('WP %d' % w if w in drop_w else d)] += s
MB = 1048576.0
print('\nall generated sizes on disk: %.0f MB' % (allsize_b / MB))
print('PRUNABLE (drop list, not referenced anywhere): %d files, %.0f MB ; protected because referenced: %d files (%.0f MB)' % (tot['files'], tot['bytes'] / MB, prot_n, kept_b / MB))
for k, b in byd.most_common(): print('   %-22s %7.0f MB' % (k, b / MB))
