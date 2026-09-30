import os, re, subprocess, collections

ROOT = '/Users/mirelaristic/Projects/jerkovic'
UP = ROOT + '/wp-content/uploads'

def q(sql):
    r = subprocess.run(['ddev', 'wp', 'db', 'query', sql, '--skip-column-names', '--batch', '--raw', '--skip-themes', '--skip-plugins'],
                       cwd=ROOT, capture_output=True, text=True)
    return r.stdout

rows = q("SELECT post_id, meta_value FROM wp_postmeta WHERE meta_key='_wp_attachment_metadata'").split('\n')
listed = set(); name_files = collections.Counter(); name_bytes = collections.Counter()
n_att = 0
for row in rows:
    if '\t' not in row: continue
    pid, val = row.split('\t', 1)
    m = re.search(r'"file";s:\d+:"([^"]+)"', val)
    if not m: continue
    n_att += 1
    main = m.group(1); d = os.path.dirname(main)
    listed.add(main)
    mo = re.search(r'"original_image";s:\d+:"([^"]+)"', val)
    if mo: listed.add(d + '/' + mo.group(1))
    # sizes:  s:N:"name";a:5:{s:4:"file";s:N:"basename"; ...
    for sm in re.finditer(r's:\d+:"([^"]+)";a:\d+:\{s:4:"file";s:\d+:"([^"]+)"', val):
        name, fn = sm.groups()
        rel = d + '/' + fn
        listed.add(rel)
        p = os.path.join(UP, rel)
        if os.path.exists(p):
            name_files[name] += 1; name_bytes[name] += os.path.getsize(p)
print('attachments with metadata:', n_att, ' distinct files listed in metadata:', len(listed))

sufre = re.compile(r'-(\d{2,4})x(\d{2,4})\.(jpe?g|png|webp|gif|avif)$', re.I)
stray = collections.Counter(); stray_b = 0; stray_n = 0; listed_b = 0; listed_n = 0
for dp, dn, fn in os.walk(UP):
    rd = os.path.relpath(dp, UP)
    if not re.match(r'^\d{4}', rd): continue
    for f in fn:
        if not sufre.search(f): continue
        rel = rd + '/' + f; s = os.path.getsize(os.path.join(dp, f))
        if rel in listed: listed_n += 1; listed_b += s
        else: stray_n += 1; stray_b += s
MB = 1048576.0
print('size files on disk listed in metadata: %d (%.0f MB) ; NOT listed (stray): %d (%.0f MB)' % (listed_n, listed_b / MB, stray_n, stray_b / MB))
print('\nsize NAMES in metadata (files that exist on disk, MB):')
for n, b in name_bytes.most_common(45): print('  %-26s %6d files %7.0f MB' % (n, name_files[n], b / MB))
