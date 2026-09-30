import os, re, subprocess, hashlib

ROOT = '/Users/mirelaristic/Projects/jerkovic'
UP = ROOT + '/wp-content/uploads'
CLONE = '/Users/mirelaristic/Projects/jerkovic-uploads-backup-20260930'

def q(sql):
    r = subprocess.run(['ddev', 'wp', 'db', 'query', sql, '--skip-column-names', '--batch', '--raw', '--skip-themes', '--skip-plugins'],
                       cwd=ROOT, capture_output=True, text=True)
    return r.stdout

def walk(root):
    out = {}
    for dp, dn, fn in os.walk(root):
        for f in fn:
            p = os.path.join(dp, f)
            out[os.path.relpath(p, root)] = os.path.getsize(p)
    return out

a = walk(UP); b = walk(CLONE)
print('files: now=%d clone=%d  only-now=%d only-clone=%d  size-mismatch=%d' % (
    len(a), len(b), len(set(a) - set(b)), len(set(b) - set(a)), sum(1 for k in a if k in b and a[k] != b[k])))

def meta(dbprefix):
    rows = q("SELECT post_id, meta_value FROM %swp_postmeta WHERE meta_key='_wp_attachment_metadata'" % dbprefix).split('\n')
    out = {}
    for row in rows:
        if '\t' not in row: continue
        pid, val = row.split('\t', 1)
        pairs = set(re.findall(r's:\d+:"([^"]+)";a:\d+:\{s:4:"file";s:\d+:"([^"]+)"', val))
        m = re.search(r'"file";s:\d+:"([^"]+)"', val)
        out[pid] = (m.group(1) if m else None, frozenset(pairs))
    return out

cur = meta(''); orig = meta('testdb.')
diff = [k for k in set(cur) | set(orig) if cur.get(k) != orig.get(k)]
print('attachments in metadata: now=%d original=%d ; attachments whose size set differs: %d' % (len(cur), len(orig), len(diff)))
for k in diff[:5]:
    print('  id', k, 'now', len(cur.get(k, (0, ()))[1]), 'orig', len(orig.get(k, (0, ()))[1]))
