import os, re, json, collections
UP = '/Users/mirelaristic/Projects/jerkovic/wp-content/uploads'
crawl_files = set(json.load(open('/tmp/umc_crawl_files.json')))
hard = {(36,36),(180,180),(120,120),(260,185),(450,450),(495,400),(710,375),(845,321),(845,684),(1210,423),(1500,430),(1500,630)}
soft_bounds = {'medium 300': (300,300), 'medium_large 768': (768,None), 'large 1030': (1030,1030), 'WP1536': (1536,1536),
               'WP2048': (2048,2048), 'extra_large 1500': (1500,1500), 'masonry 705': (705,705), 'shop_single 450x999': (450,999)}
def soft_match(w, h):
    for name, (bw, bh) in soft_bounds.items():
        if bh is None:
            if w == bw: return name
        elif (w == bw and h <= bh) or (h == bh and w <= bw):
            return name
    return None
sufre = re.compile(r'-(\d{2,4})x(\d{2,4})\.(jpe?g|png|webp|gif|avif)$', re.I)
cat = collections.Counter(); catn = collections.Counter(); unreg_dims = collections.Counter(); unreg_ref = collections.Counter()
for dp, dn, fn in os.walk(UP):
    rel_dir = os.path.relpath(dp, UP)
    if not re.match(r'^\d{4}', rel_dir): continue
    for f in fn:
        m = sufre.search(f)
        if not m: continue
        w, h = int(m.group(1)), int(m.group(2)); s = os.path.getsize(os.path.join(dp, f))
        rel = rel_dir + '/' + f
        if (w, h) in hard: c = 'registered hard: %dx%d' % (w, h)
        else:
            sm = soft_match(w, h)
            if sm: c = 'registered soft: ' + sm
            else:
                c = 'UNREGISTERED (legacy)'
                unreg_dims['%dx%d' % (w, h)] += s
                if rel in crawl_files: unreg_ref['referenced by live HTML'] += s
                else: unreg_ref['not requested by live HTML'] += s
        cat[c] += s; catn[c] += 1
MB = 1048576.0
tot = sum(cat.values())
print('generated sizes total: %.0f MB\n' % (tot / MB))
for k, b in cat.most_common(): print('%-34s %7d files %7.0f MB' % (k, catn[k], b / MB))
print('\nUNREGISTERED split:', {k: round(v / MB) for k, v in unreg_ref.items()})
print('distinct unregistered dims:', len(unreg_dims))
print('top unregistered dims:', [(d, round(b / MB)) for d, b in unreg_dims.most_common(12)])
