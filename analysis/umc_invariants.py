import os, re, subprocess, sys

ROOT = '/Users/mirelaristic/Projects/jerkovic'
UP = ROOT + '/wp-content/uploads'
CLONE = '/Users/mirelaristic/Projects/jerkovic-uploads-backup-20260930'
BASE = '/Users/mirelaristic/Projects/jerkovic-media-cleanup/'

def q(sql):
    r = subprocess.run(['ddev', 'wp', 'db', 'query', sql, '--skip-column-names', '--batch', '--raw', '--skip-themes', '--skip-plugins'],
                       cwd=ROOT, capture_output=True, text=True)
    return r.stdout

def walk(root):
    out = set()
    for dp, dn, fn in os.walk(root):
        for f in fn:
            out.add(os.path.relpath(os.path.join(dp, f), root))
    return out

now = walk(UP); before = walk(CLONE)
removed = before - now; added = now - before
print('files before: %d  now: %d  removed: %d  added: %d' % (len(before), len(now), len(removed), len(added)))

# 1. live files that existed before must still exist
live = [l.strip() for l in open(BASE + 'live-files.txt') if l.strip()]
lost_live = [f for f in live if f in before and f not in now]
print('live-HTML files that existed before but are gone now: %d' % len(lost_live))
for f in lost_live[:10]: print('   LOST', f)

# 2. metadata invariants
rows = q("SELECT post_id, meta_value FROM wp_postmeta WHERE meta_key='_wp_attachment_metadata'").split('\n')
missing = []; n = 0; sized = 0
for row in rows:
    if '\t' not in row: continue
    pid, val = row.split('\t', 1)
    m = re.search(r'"file";s:\d+:"([^"]+)"', val)
    if not m: continue
    n += 1; d = os.path.dirname(m.group(1))
    for sm in re.finditer(r's:\d+:"([^"]+)";a:\d+:\{s:4:"file";s:\d+:"([^"]+)"', val):
        sized += 1
        rel = d + '/' + sm.group(2)
        if rel not in now and rel in before:      # listed in metadata but we removed it
            missing.append((pid, sm.group(1), rel))
print('attachments: %d, size entries still in metadata: %d' % (n, sized))
print('metadata entries pointing to files that were REMOVED by us: %d' % len(missing))
for x in missing[:10]: print('   ', x)

# 3. sanity: everything removed from uploads is in quarantine list
qdir = ROOT + '/wp-content/umc-work/quarantine'
qfiles = walk(qdir) if os.path.isdir(qdir) else set()
print('removed files not found in quarantine: %d' % len(removed - qfiles))
print('quarantine files not removed from uploads: %d' % len(qfiles - removed))
# 4. kept sizes: how many files by size name remain
kept = sum(1 for r in now if re.search(r'-495x400\.', r))
print('495x400 (portfolio) files remaining: %d (was %d)' % (kept, sum(1 for r in before if re.search(r'-495x400\.', r))))
