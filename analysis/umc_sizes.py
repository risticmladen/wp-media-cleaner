import re, subprocess, collections, sys

def q(sql):
    r = subprocess.run(['ddev', 'wp', 'db', 'query', sql, '--skip-column-names', '--batch', '--skip-themes', '--skip-plugins'],
                       cwd='/Users/mirelaristic/Projects/jerkovic', capture_output=True, text=True)
    return r.stdout

# content of all live posts (not revisions/trash/attachments) + builder meta
posts = q("SELECT CONCAT(post_type,'|',post_status,'|',ID,'|') , post_content FROM wp_posts WHERE post_type NOT IN ('revision','attachment','nav_menu_item') AND post_status NOT IN ('trash','auto-draft')")
meta = q("SELECT meta_key, meta_value FROM wp_postmeta WHERE meta_key IN ('_aviaLayoutBuilderCleanData','_avia_builder_shortcode_tree') AND post_id IN (SELECT ID FROM wp_posts WHERE post_status NOT IN ('trash','auto-draft') AND post_type<>'revision')")
opts = q("SELECT option_name, option_value FROM wp_options WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'")
text = posts
print('content chars: posts=%d meta=%d options=%d' % (len(posts), len(meta), len(opts)))

attr = re.compile(r"""\b((?:[a-z_]*size[a-z_]*|thumb_size|preview_size|img_size|image_size|attachment_size|columns))=(?:'|\")([^'"]*)(?:'|\")""")
cnt = collections.defaultdict(collections.Counter)
for m in attr.finditer(text):
    a, v = m.groups()
    if a == 'columns': continue
    cnt[a][v] += 1
print('\n== size-related shortcode attributes in live page content ==')
for a, c in sorted(cnt.items()):
    print(' %-18s %s' % (a, dict(c.most_common(12))))

# size-xxx classes on <img>
cls = collections.Counter(re.findall(r'\bsize-([a-z_0-9-]+)\b', text))
print('\n== <img class="size-..."> ==', dict(cls.most_common(15)))

# explicit -WxH in URLs, from content + meta + options (uploads URLs only)
dims = collections.Counter()
urlre = re.compile(r'uploads/[^\s"\'<>()]*?-(\d{2,4})x(\d{2,4})\.(?:jpe?g|png|webp|gif|avif)', re.I)
for src_name, src in (('content', posts), ('builder-meta', meta), ('options', opts)):
    c = collections.Counter('%sx%s' % m for m in urlre.findall(src.replace('\\/', '/')))
    print('\n== explicit -WxH URL references in %s: %d distinct, %d total ==' % (src_name, len(c), sum(c.values())))
    print('  ', dict(c.most_common(15)))
    dims.update(c)
open('/tmp/umc_used_dims.txt', 'w').write('\n'.join('%s %d' % kv for kv in dims.most_common()))
