<?php
/**
 * Unused Media Cleanup (WordPress, PHP 7.2+)
 *
 * Pronalazi slike u wp-content/uploads koje se nigdje ne koriste i sigurno ih uklanja.
 *
 * KAKO RADI
 *  1. SKEN: cita SVE tekstualne stupce iz baze (posts, postmeta, options, layerslider, ...),
 *     te CSS/JS/PHP datoteke teme, i skuplja sve reference na slike (URL-ovi i ID-jevi).
 *  2. Svaka datoteka u uploads se svrstava u "obitelj" (original + -300x200 + -scaled + .webp ...).
 *     Ako je BILO STO iz obitelji referencirano, cijela obitelj se cuva (konzervativno).
 *  3. Obitelji bez ikakve reference su:
 *       O = orphan    (datoteka nije u Media Library i nigdje se ne koristi)
 *       A = attachment (u Media Library je, ali se nigdje ne koristi)
 *  4. PRIMJENA: datoteke se PREMJESTE u wp-content/umc-work/quarantine (mogu se vratiti),
 *     a tek onda (opcionalno) trajno obrisati.
 *
 * UPUTE
 *  - Kopiraj ovu datoteku u KORIJEN WordPress instalacije (pored wp-load.php).
 *  - (Opcionalno) stavi known-unused.txt pored nje: jedna slika po retku (ime datoteke,
 *    URL ili CSV redak). Skripta ce javiti koliko ih je prepoznala kao nekoristene.
 *  - Otvori https://tvoja-domena/unused-media-cleanup.php kao prijavljeni administrator.
 *  - Kad zavrsis, OBRISI ovu datoteku sa servera.
 */

if (!defined('ABSPATH')) {
    $umc_loaded = false;
    foreach (array(__DIR__, dirname(__DIR__), dirname(dirname(__DIR__))) as $umc_dir) {
        if (is_file($umc_dir . '/wp-load.php')) {
            require_once $umc_dir . '/wp-load.php';
            $umc_loaded = true;
            break;
        }
    }
    if (!$umc_loaded) {
        header('HTTP/1.1 500 Internal Server Error');
        exit('wp-load.php nije pronadjen. Stavi skriptu u korijen WordPress instalacije.');
    }
}

/* ------------------------------------------------------------------------- */
/* Konfiguracija                                                             */
/* ------------------------------------------------------------------------- */

function umc_cfg()
{
    static $c = null;
    if ($c === null) {
        $c = array(
            // sekundi rada po HTTP zahtjevu (skripta se sama nastavlja)
            'time_budget'     => 20,
            'rows_per_batch'  => 100,
            // sto se smatra kandidatom za brisanje (dodaj 'mp4','pdf' ako zelis i njih)
            'candidate_ext'   => array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff'),
            // sve sto prepoznajemo kao referencu u tekstu
            'ref_ext'         => array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'svg', 'ico',
                                       'mp4', 'webm', 'mov', 'm4v', 'ogv', 'mp3', 'wav', 'pdf'),
            // mape u uploads koje se NIKAD ne diraju
            'protected_dirs'  => array('layerslider', 'dynamic_avia', 'fusion-scripts', 'fusion-styles', 'fusionredux',
                                       'wc-logs', 'woocommerce_uploads', 'ai1wm-backups', 'cache', 'wpcf7_uploads',
                                       'elementor', 'smush'),
            // true = diraj samo mape oblika uploads/GODINA/...
            'only_dated_dirs' => true,
            // preskoci datoteke mladje od N dana (0 = ne preskaci)
            'min_age_days'    => 0,
            // dodatne mape u kojima se traze reference (apsolutne putanje)
            'extra_scan_dirs' => array(),
            // tablice (bez prefiksa) koje se ne citaju
            'skip_tables'     => array('users', 'term_relationships', 'actionscheduler_logs', 'actionscheduler_actions'),
            'known_list'      => __DIR__ . '/known-unused.txt',
            // popis URL-ova slika koje zivi HTML stvarno trazi (jedan po retku), generira ga crawler
            'live_files'      => __DIR__ . '/live-files.txt',
            // faza B: JEDINE velicine slika koje se zadrzavaju; sve ostale (po imenu u metapodacima) se uklanjaju
            'keep_sizes'      => array('full', 'thumbnail', 'square', 'medium', 'large', 'widget', 'portfolio', 'gallery',
                                       'featured', 'masonry', 'shop_thumbnail', 'shop_catalog', 'shop_single'),
        );
        $met = (int) ini_get('max_execution_time');
        if ($met > 0 && $met < 30) {
            $c['time_budget'] = max(5, $met - 8);
        }
    }
    return $c;
}

/* ------------------------------------------------------------------------- */
/* Pomocne funkcije                                                          */
/* ------------------------------------------------------------------------- */

function umc_up()
{
    static $p = null;
    if ($p === null) {
        $u = wp_get_upload_dir();
        $p = rtrim(str_replace('\\', '/', $u['basedir']), '/');
    }
    return $p;
}

function umc_work()
{
    $w = rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/umc-work';
    if (!is_dir($w)) {
        wp_mkdir_p($w);
        @file_put_contents($w . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        @file_put_contents($w . '/index.php', "<?php\n// Silence is golden.\n");
        @file_put_contents($w . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs="" /><add accessType="Deny" users="*" /></authorization></security></system.webServer></configuration>');
    }
    return $w;
}

function umc_fmt_bytes($b)
{
    $u = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    $b = (float) $b;
    while ($b >= 1024 && $i < 4) {
        $b /= 1024;
        $i++;
    }
    return number_format($b, $i ? 1 : 0, ',', '.') . ' ' . $u[$i];
}

function umc_norm($s)
{
    if (function_exists('normalizer_normalize') && preg_match('/[^\x20-\x7e]/', $s)) {
        $n = normalizer_normalize($s);
        if ($n !== false) {
            $s = $n;
        }
    }
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function umc_ext_regex()
{
    static $r = null;
    if ($r === null) {
        $r = implode('|', umc_cfg()['ref_ext']);
    }
    return $r;
}

/**
 * Kljuc "obitelji" slike: mapa + ime bez ekstenzije i bez sufiksa velicine.
 * 2020/06/Foto-300x200.webp  ->  2020/06/foto
 */
function umc_key($rel)
{
    $rel = umc_norm(ltrim(str_replace('\\', '/', $rel), '/'));
    $dir = dirname($rel);
    $base = basename($rel);
    $re_ext = '/\.(?:' . umc_ext_regex() . ')$/';
    while (preg_match($re_ext, $base)) {
        $base = preg_replace($re_ext, '', $base);
    }
    do {
        $prev = $base;
        $base = preg_replace('/(?:-(?:\d+x\d+|scaled|rotated|e\d{10,})|@\dx)$/', '', $base);
    } while ($base !== $prev);
    return ($dir === '.' || $dir === '') ? $base : $dir . '/' . $base;
}

function umc_ref_key($p)
{
    if (strpos($p, '&') !== false) {
        $p = html_entity_decode($p, ENT_QUOTES, 'UTF-8');
    }
    if (strpos($p, '%') !== false) {
        $p = rawurldecode($p);
    }
    $p = preg_replace('/[?#].*$/', '', $p);
    return umc_key($p);
}

/** Tocan relativni put datoteke u uploads (bez obitelji), normaliziran za usporedbu. */
function umc_exact_key($p)
{
    if (strpos($p, '&') !== false) {
        $p = html_entity_decode($p, ENT_QUOTES, 'UTF-8');
    }
    if (strpos($p, '%') !== false) {
        $p = rawurldecode($p);
    }
    $p = preg_replace('/[?#].*$/', '', $p);
    $p = ltrim(str_replace('\\', '/', $p), '/');
    if (strpos($p, 'uploads/') === 0) {
        $p = substr($p, 8);
    }
    return umc_norm($p);
}

/** Izvuci reference (putanje + ID-jeve attachmenta) iz proizvoljnog teksta. */
function umc_extract($text, array &$paths, array &$ids, &$exact = null)
{
    static $re_a = null, $re_b = null;
    if (!is_string($text) || strlen($text) < 5) {
        return;
    }
    if ($re_a === null) {
        $e = umc_ext_regex();
        $cls = '[^\s"\'<>()\\\\,;|]';
        $re_a = '#uploads/(' . $cls . '*?\.(?:' . $e . '))(?![A-Za-z0-9])#i';
        $re_b = '#(?<![A-Za-z0-9_/.\-])(\d{4}/\d{2}/' . $cls . '*?\.(?:' . $e . '))(?![A-Za-z0-9])#i';
    }
    if (strpos($text, '\\') !== false) {
        $text = preg_replace('#\\\\+/#', '/', $text);
    }
    if (preg_match_all($re_a, $text, $m)) {
        foreach ($m[1] as $p) {
            $paths[umc_ref_key($p)] = 1;
            if ($exact !== null) {
                $exact[umc_exact_key($p)] = 1;
            }
        }
    }
    if (preg_match_all($re_b, $text, $m)) {
        foreach ($m[1] as $p) {
            $paths[umc_ref_key($p)] = 1;
            if ($exact !== null) {
                $exact[umc_exact_key($p)] = 1;
            }
        }
    }
    if (strpos($text, 'wp-image-') !== false && preg_match_all('/wp-image-(\d+)/', $text, $m)) {
        foreach ($m[1] as $d) {
            $ids[(int) $d] = 1;
        }
    }
    $re_id = '/(?<![A-Za-z0-9])(?:[A-Za-z0-9]+[_-])*(?:[A-Za-z]*(?:Ids?|IDs?)|ids?|attachments?|include|gallery)["\']?\s*[=:]\s*["\']?\[?\s*(\d+(?:\s*,\s*\d+)*)/';
    if (preg_match_all($re_id, $text, $m)) {
        foreach ($m[1] as $v) {
            foreach (preg_split('/\D+/', $v, -1, PREG_SPLIT_NO_EMPTY) as $d) {
                $ids[(int) $d] = 1;
            }
        }
    }
    if (strpos($text, 's:') !== false) {
        $re_ser = '/s:\d+:"(?:[^"]*[_-])?(?:[A-Za-z]*(?:Ids?|IDs?)|ids?|attachments?|image|img|logo|icon|thumbnail|thumb|background|bg|gallery|photo|picture|cover|banner|media)";(?:i:(\d+)|s:\d+:"(\d+(?:,\d+)*)")/';
        if (preg_match_all($re_ser, $text, $m)) {
            foreach ($m[1] as $i => $v) {
                $v = ($v !== '') ? $v : $m[2][$i];
                foreach (preg_split('/\D+/', $v, -1, PREG_SPLIT_NO_EMPTY) as $d) {
                    $ids[(int) $d] = 1;
                }
            }
        }
    }
}

/** Ako je vrijednost meta/option cisti popis brojeva, a naziv kljuca upucuje na sliku -> ID-jevi. */
function umc_key_ids($key, $val, array &$ids)
{
    if (!is_string($val) || !preg_match('/^\s*\d+(?:\s*,\s*\d+)*\s*$/', $val)) {
        return;
    }
    if (!preg_match('/(?:^|[_-])(?:ids?|thumbnail|image|img|logo|icon|thumb|gallery|photo|picture|cover|banner|media|attachment|background|bg)(?:s|_ids?)?$/i', (string) $key)) {
        return;
    }
    foreach (preg_split('/\D+/', $val, -1, PREG_SPLIT_NO_EMPTY) as $d) {
        $ids[(int) $d] = 1;
    }
}

function umc_state_load()
{
    $f = umc_work() . '/state.ser';
    if (!is_file($f)) {
        return null;
    }
    $s = @unserialize(file_get_contents($f), array('allowed_classes' => false));
    return is_array($s) ? $s : null;
}

function umc_state_save(array $s)
{
    $f = umc_work() . '/state.ser';
    $tmp = $f . '.tmp';
    file_put_contents($tmp, serialize($s));
    @rename($tmp, $f);
}

function umc_log($action, $rel)
{
    static $fh = null;
    if ($fh === null) {
        $fh = fopen(umc_work() . '/actions.log', 'a');
    }
    if ($fh) {
        fwrite($fh, date('c') . "\t" . $action . "\t" . $rel . "\n");
    }
}

/* ------------------------------------------------------------------------- */
/* Faza 1: citanje baze                                                      */
/* ------------------------------------------------------------------------- */

function umc_build_steps($notrash = false)
{
    global $wpdb;
    $cfg = umc_cfg();
    $prefix = $wpdb->base_prefix;
    $tables = $wpdb->get_col("SHOW TABLES LIKE '" . esc_sql($wpdb->esc_like($prefix)) . "%'");
    $steps = array();
    foreach ($tables as $t) {
        $suffix = substr($t, strlen($prefix));
        if (in_array($suffix, $cfg['skip_tables'], true)) {
            continue;
        }
        $cols = $wpdb->get_results('SHOW COLUMNS FROM `' . $t . '`', ARRAY_A);
        $text = array();
        $pkcol = null;
        $pkcount = 0;
        foreach ($cols as $c) {
            if (preg_match('/char|text|blob|json/i', $c['Type'])) {
                $text[] = $c['Field'];
            }
            if ($c['Key'] === 'PRI') {
                $pkcount++;
                $pkcol = $c;
            }
        }
        if (!$text) {
            continue;
        }
        $pk = ($pkcount === 1 && preg_match('/int/i', $pkcol['Type'])) ? $pkcol['Field'] : null;
        $where = '';
        $key = '';
        if ($suffix === 'posts') {
            $text = array_values(array_intersect($text, array('post_content', 'post_excerpt', 'post_content_filtered')));
            $where = "post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft'";
            if ($notrash) {
                $where .= " AND post_status <> 'trash'";
            }
        } elseif ($suffix === 'postmeta') {
            $text = array('meta_value');
            $key = 'meta_key';
            $where = "meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_wp_attachment_image_alt','_edit_lock','_edit_last')";
            if ($notrash) {
                $where .= " AND post_id NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash')";
            }
        } elseif ($suffix === 'options') {
            $text = array('option_value');
            $key = 'option_name';
            $where = "LEFT(option_name, 11) <> '_transient_' AND LEFT(option_name, 16) <> '_site_transient_'";
        } elseif ($suffix === 'usermeta' || $suffix === 'termmeta' || $suffix === 'commentmeta') {
            $text = array('meta_value');
            $key = 'meta_key';
        }
        if (!$text) {
            continue;
        }
        $steps[] = array('t' => $t, 'cols' => $text, 'pk' => $pk, 'where' => $where, 'key' => $key);
    }
    return $steps;
}

/** @return bool true kad su sve tablice gotove */
function umc_db_step(array &$s, $deadline)
{
    global $wpdb;
    $n = (int) umc_cfg()['rows_per_batch'];
    while ($s['si'] < count($s['steps'])) {
        $st = $s['steps'][$s['si']];
        while (true) {
            if (microtime(true) > $deadline) {
                return false;
            }
            $sel = array();
            if ($st['pk']) {
                $sel[] = '`' . $st['pk'] . '`';
            }
            foreach ($st['cols'] as $c) {
                $sel[] = '`' . $c . '`';
            }
            if ($st['key']) {
                $sel[] = '`' . $st['key'] . '`';
            }
            $w = $st['where'] ? '(' . $st['where'] . ')' : '1=1';
            if ($st['pk']) {
                $sql = 'SELECT ' . implode(',', $sel) . ' FROM `' . $st['t'] . '` WHERE `' . $st['pk'] . '` > ' . (int) $s['cur'] . ' AND ' . $w . ' ORDER BY `' . $st['pk'] . '` ASC LIMIT ' . $n;
            } else {
                $sql = 'SELECT ' . implode(',', $sel) . ' FROM `' . $st['t'] . '` WHERE ' . $w . ' LIMIT ' . (int) $s['cur'] . ',' . $n;
            }
            $rows = $wpdb->get_results($sql, ARRAY_N);
            $wpdb->flush();
            $wpdb->queries = array();
            if (!$rows) {
                break;
            }
            $off = $st['pk'] ? 1 : 0;
            foreach ($rows as $r) {
                $keyval = $st['key'] ? (string) $r[count($r) - 1] : '';
                foreach ($st['cols'] as $i => $c) {
                    $val = $r[$off + $i];
                    umc_extract($val, $s['paths'], $s['ids'], $s['exact']);
                    if ($keyval !== '') {
                        umc_key_ids($keyval, $val, $s['ids']);
                    }
                }
                $s['rows']++;
                if ($st['pk']) {
                    $s['cur'] = (int) $r[0];
                }
            }
            if (!$st['pk']) {
                $s['cur'] += count($rows);
            }
            $done = count($rows) < $n;
            unset($rows);
            if ($done) {
                break;
            }
        }
        $s['si']++;
        $s['cur'] = 0;
    }
    return true;
}

/* ------------------------------------------------------------------------- */
/* Faza 2: datoteke teme / CSS                                               */
/* ------------------------------------------------------------------------- */

function umc_collect_scan_files()
{
    $cfg = umc_cfg();
    $roots = array_merge(
        array(get_theme_root(), WP_CONTENT_DIR . '/mu-plugins', umc_up() . '/dynamic_avia'),
        $cfg['extra_scan_dirs']
    );
    $exts = array('css', 'js', 'php', 'html', 'htm', 'json', 'svg', 'txt', 'xml', 'scss', 'less');
    $skipdirs = array('node_modules', 'demo_files', '.git', 'languages', 'lang', 'locales');
    $out = array();
    foreach ($roots as $r) {
        if (!is_dir($r)) {
            continue;
        }
        $dir = new RecursiveDirectoryIterator($r, FilesystemIterator::SKIP_DOTS);
        $flt = new RecursiveCallbackFilterIterator($dir, function ($cur, $key, $iter) use ($skipdirs) {
            if ($iter->hasChildren()) {
                return !in_array($cur->getFilename(), $skipdirs, true);
            }
            return true;
        });
        foreach (new RecursiveIteratorIterator($flt) as $f) {
            if (!$f->isFile() || $f->getSize() > 4 * 1024 * 1024) {
                continue;
            }
            if (in_array(strtolower($f->getExtension()), $exts, true)) {
                $out[] = $f->getPathname();
            }
        }
    }
    return $out;
}

function umc_files_step(array &$s, $deadline)
{
    $total = count($s['ffiles']);
    while ($s['fi'] < $total) {
        if (microtime(true) > $deadline) {
            return false;
        }
        $txt = @file_get_contents($s['ffiles'][$s['fi']]);
        if ($txt !== false) {
            umc_extract($txt, $s['paths'], $s['ids'], $s['exact']);
        }
        $s['fi']++;
    }
    return true;
}

/* ------------------------------------------------------------------------- */
/* Faza 3: registar attachmenta                                              */
/* ------------------------------------------------------------------------- */

function umc_registry(array &$s)
{
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_parent, m.meta_value AS f FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
         WHERE p.post_type = 'attachment'",
        ARRAY_A
    );
    $parents = array();
    foreach ($rows as $r) {
        if ((int) $r['post_parent'] > 0) {
            $parents[(int) $r['post_parent']] = 1;
        }
    }
    $valid = array();
    foreach (array_chunk(array_keys($parents), 500) as $chunk) {
        $res = $wpdb->get_results(
            "SELECT ID FROM {$wpdb->posts} WHERE ID IN (" . implode(',', array_map('intval', $chunk)) . ")
             AND post_type NOT IN ('revision','attachment') AND post_status NOT IN ('trash','auto-draft')",
            ARRAY_A
        );
        foreach ($res as $x) {
            $valid[(int) $x['ID']] = 1;
        }
    }
    $reg = array();
    $nofile = 0;
    foreach ($rows as $r) {
        if (empty($r['f'])) {
            $nofile++;
            continue;
        }
        $id = (int) $r['ID'];
        $k = umc_key($r['f']);
        if (!isset($reg[$k])) {
            $reg[$k] = array('ids' => array(), 'u' => 0);
        }
        $reg[$k]['ids'][] = $id;
        $u = 0;
        if (isset($s['ids'][$id])) {
            $u = 1;
        } elseif (!$s['strict'] && isset($valid[(int) $r['post_parent']])) {
            $u = 2;
        }
        if ($u !== 0 && ($reg[$k]['u'] === 0 || $u < $reg[$k]['u'])) {
            $reg[$k]['u'] = $u;
        }
    }
    $s['reg'] = $reg;
    $s['reg_total'] = count($rows);
    $s['reg_nofile'] = $nofile;
}

/* ------------------------------------------------------------------------- */
/* Faza 4: obilazak uploads foldera i klasifikacija                          */
/* ------------------------------------------------------------------------- */

function umc_known_list()
{
    $f = umc_cfg()['known_list'];
    $out = array();
    if (!is_readable($f)) {
        return $out;
    }
    $re = '#([^\s,;"\'\t/\\\\]+\.(?:' . umc_ext_regex() . '))#i';
    foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match($re, $line, $m)) {
            $name = rawurldecode($m[1]);
            $bk = basename(umc_key('x/' . $name));
            if (!isset($out[$bk])) {
                $out[$bk] = array('name' => $name, 'n' => 0, 'u' => 0, 'r' => array());
            }
        }
    }
    return $out;
}

function umc_walk(array &$s, $deadline)
{
    $cfg = umc_cfg();
    $up = umc_up();
    $fh = fopen(umc_work() . '/unused.tsv', 'c+');
    ftruncate($fh, (int) $s['upos']);
    fseek($fh, 0, SEEK_END);
    $cand = array_flip($cfg['candidate_ext']);
    $prot = array_map('strtolower', $cfg['protected_dirs']);
    $minage = $cfg['min_age_days'] > 0 ? time() - $cfg['min_age_days'] * 86400 : 0;
    $st =& $s['st'];

    while ($s['stack']) {
        if (microtime(true) > $deadline) {
            break;
        }
        $dirrel = array_pop($s['stack']);
        $abs = $dirrel === '' ? $up : $up . '/' . $dirrel;
        $names = @scandir($abs);
        if ($names === false) {
            $st['unread']++;
            continue;
        }
        foreach ($names as $nm) {
            if ($nm === '.' || $nm === '..') {
                continue;
            }
            $rel = $dirrel === '' ? $nm : $dirrel . '/' . $nm;
            $p = $abs . '/' . $nm;
            if (is_link($p)) {
                continue;
            }
            if (is_dir($p)) {
                if ($dirrel === '') {
                    if (in_array(strtolower($nm), $prot, true)) {
                        continue;
                    }
                    if ($cfg['only_dated_dirs'] && !preg_match('/^\d{4}$/', $nm)) {
                        continue;
                    }
                }
                $s['stack'][] = $rel;
                continue;
            }
            $st['files']++;
            $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
            if (!isset($cand[$ext]) || strpbrk($rel, "\t\n\r") !== false) {
                $st['other']++;
                continue;
            }
            $size = (int) @filesize($p);
            $st['cand']++;
            $st['cand_b'] += $size;
            if ($minage && (int) @filemtime($p) > $minage) {
                $st['recent']++;
                continue;
            }
            $key = umc_key($rel);
            $bk = basename($key);
            $reason = '';
            $class = '';
            if (isset($s['paths'][$key])) {
                $reason = 'up';
            } elseif (isset($s['reg'][$key])) {
                $u = $s['reg'][$key]['u'];
                if ($u === 1) {
                    $reason = 'ui';
                } elseif ($u === 2) {
                    $reason = 'upar';
                } else {
                    $class = 'A';
                }
            } else {
                $class = 'O';
            }
            if ($reason !== '') {
                $st[$reason]++;
                $st[$reason . '_b'] += $size;
            } else {
                $st['u' . $class]++;
                $st['u' . $class . '_b'] += $size;
                $ids = '';
                if ($class === 'A') {
                    $ids = implode(',', $s['reg'][$key]['ids']);
                    foreach ($s['reg'][$key]['ids'] as $id) {
                        $s['att_unused'][$id] = 1;
                    }
                }
                fwrite($fh, $rel . "\t" . $size . "\t" . $class . "\t" . $ids . "\n");
            }
            if (isset($s['known'][$bk])) {
                $s['known'][$bk]['n']++;
                if ($reason === '') {
                    $s['known'][$bk]['u']++;
                } else {
                    $s['known'][$bk]['r'][$reason] = 1;
                }
            }
        }
        fflush($fh);
        $s['upos'] = ftell($fh);
    }
    fclose($fh);
    return empty($s['stack']);
}

/* ------------------------------------------------------------------------- */
/* Primjena (karantena / brisanje)                                           */
/* ------------------------------------------------------------------------- */

function umc_apply_files(array &$s, $deadline)
{
    $a =& $s['apply'];
    $fh = @fopen(umc_work() . '/unused.tsv', 'r');
    if (!$fh) {
        $a['phase'] = 'done';
        return;
    }
    fseek($fh, (int) $a['pos']);
    $up = umc_up();
    $realup = str_replace('\\', '/', (string) realpath($up));
    $q = umc_work() . '/quarantine';
    $n = 0;
    while (($line = fgets($fh)) !== false) {
        $a['pos'] = ftell($fh);
        $p = explode("\t", rtrim($line, "\r\n"));
        if (count($p) < 4) {
            continue;
        }
        list($rel, $size, $cls) = $p;
        $want = ($a['mode'] === 'all') || ($a['mode'] === 'orphans' && $cls === 'O') || ($a['mode'] === 'attachments' && $cls === 'A');
        if (!$want) {
            continue;
        }
        $src = $up . '/' . $rel;
        if (strpos($rel, '..') !== false || !is_file($src) || is_link($src)) {
            $a['miss']++;
            continue;
        }
        $real = str_replace('\\', '/', (string) realpath($src));
        if ($real === '' || strpos($real, $realup . '/') !== 0) {
            $a['err']++;
            continue;
        }
        if ($a['method'] === 'delete') {
            $ok = @unlink($src);
            $act = 'DELETE';
        } else {
            $dst = $q . '/' . $rel;
            wp_mkdir_p(dirname($dst));
            $ok = @rename($src, $dst);
            if (!$ok && @copy($src, $dst)) {
                $ok = @unlink($src);
            }
            $act = 'QUARANTINE';
        }
        if ($ok) {
            $a['done']++;
            $a['bytes'] += (int) $size;
            umc_log($act, $rel);
        } else {
            $a['err']++;
        }
        if ((++$n % 25) === 0 && microtime(true) > $deadline) {
            fclose($fh);
            return;
        }
    }
    fclose($fh);
    $a['phase'] = ($a['db'] && $a['dbids']) ? 'db' : 'done';
}

function umc_apply_db(array &$s, $deadline)
{
    $a =& $s['apply'];
    $total = count($a['dbids']);
    while ($a['dbi'] < $total) {
        if (microtime(true) > $deadline) {
            return;
        }
        $id = (int) $a['dbids'][$a['dbi']];
        $r = wp_delete_attachment($id, true);
        if ($r) {
            $a['dbdone']++;
            umc_log('DB_DELETE_ATTACHMENT', (string) $id);
        } else {
            $a['dberr']++;
        }
        $a['dbi']++;
    }
    $a['phase'] = 'done';
}

/** Vrati (ili trajno obrisi) sadrzaj karantene. @return bool true kad je gotovo */
function umc_quarantine_walk(array &$s, $deadline, $purge)
{
    $q = umc_work() . '/quarantine';
    if (!is_dir($q)) {
        return true;
    }
    $up = umc_up();
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($q, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if (microtime(true) > $deadline) {
            return false;
        }
        if ($f->isDir()) {
            @rmdir($f->getPathname());
            continue;
        }
        $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($q)), '/');
        if ($purge) {
            if (@unlink($f->getPathname())) {
                $s['rq']['n']++;
                umc_log('PURGE', $rel);
            }
        } else {
            $dst = $up . '/' . $rel;
            if (file_exists($dst)) {
                $s['rq']['skip']++;
                continue;
            }
            wp_mkdir_p(dirname($dst));
            if (@rename($f->getPathname(), $dst)) {
                $s['rq']['n']++;
                umc_log('RESTORE', $rel);
            }
        }
    }
    @rmdir($q);
    return true;
}

/* ------------------------------------------------------------------------- */
/* Faza B: velicine slika (vodeno metapodacima)                              */
/* ------------------------------------------------------------------------- */

/** Popis URL-ova slika koje su stvarno trazene u zivom HTML-u (live-files.txt, jedan po retku). */
function umc_live_files()
{
    $out = array();
    $f = umc_cfg()['live_files'];
    if (!is_readable($f)) {
        return $out;
    }
    foreach (file($f, FILE_IGNORE_NEW_LINES) as $l) {
        $l = trim($l);
        if ($l !== '') {
            $out[umc_exact_key($l)] = 1;
        }
    }
    return $out;
}

/**
 * Prolazi kroz _wp_attachment_metadata i planira uklanjanje velicina koje nisu na popisu keep_sizes,
 * osim datoteka koje su zasticene (tocna referenca u bazi/temi/zivom HTML-u, ili dijeli datoteku s
 * velicinom koja se zadrzava). Plan se sprema u sizes.tsv: id, ime velicine, datoteka, bajtovi.
 * @return bool true kad je gotovo
 */
function umc_sizes_step(array &$s, $deadline)
{
    global $wpdb;
    $keep = array_flip(umc_cfg()['keep_sizes']);
    $up = umc_up();
    $fh = fopen(umc_work() . '/sizes.tsv', 'c+');
    ftruncate($fh, (int) $s['szpos']);
    fseek($fh, 0, SEEK_END);
    $z =& $s['sz'];
    while (true) {
        if (microtime(true) > $deadline) {
            fclose($fh);
            return false;
        }
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND post_id > " . (int) $s['szcur'] . ' ORDER BY post_id ASC LIMIT 50',
            ARRAY_N
        );
        $wpdb->flush();
        $wpdb->queries = array();
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $id = (int) $r[0];
            $s['szcur'] = $id;
            if (isset($s['att_unused'][$id])) {
                continue;
            }
            $meta = @unserialize($r[1], array('allowed_classes' => false));
            if (!is_array($meta) || empty($meta['file']) || empty($meta['sizes']) || !is_array($meta['sizes'])) {
                continue;
            }
            $dir = dirname($meta['file']);
            $dir = ($dir === '.' || $dir === '') ? '' : $dir . '/';
            $keepf = array(umc_exact_key($meta['file']) => 1);
            if (!empty($meta['original_image'])) {
                $keepf[umc_exact_key($dir . $meta['original_image'])] = 1;
            }
            $ent = array();
            foreach ($meta['sizes'] as $name => $sz) {
                if (!is_array($sz) || empty($sz['file'])) {
                    continue;
                }
                $files = array($dir . $sz['file']);
                if (!empty($sz['sources']) && is_array($sz['sources'])) {
                    foreach ($sz['sources'] as $src) {
                        if (!empty($src['file'])) {
                            $files[] = $dir . $src['file'];
                        }
                    }
                }
                $ent[$name] = $files;
                if (isset($keep[$name])) {
                    foreach ($files as $f) {
                        $keepf[umc_exact_key($f)] = 1;
                    }
                }
            }
            $seen = array();
            $any = false;
            foreach ($ent as $name => $files) {
                if (isset($keep[$name])) {
                    continue;
                }
                $z['entries']++;
                $protected = false;
                foreach ($files as $f) {
                    $k = umc_exact_key($f);
                    if (isset($keepf[$k]) || isset($s['exact'][$k]) || strpbrk($f, "\t\n\r") !== false) {
                        $protected = true;
                        break;
                    }
                }
                if ($protected) {
                    $z['retained']++;
                    continue;
                }
                $has = false;
                foreach ($files as $f) {
                    $abs = $up . '/' . $f;
                    $exists = is_file($abs);
                    $b = ($exists && !isset($seen[$f])) ? (int) filesize($abs) : 0;
                    fwrite($fh, $id . "\t" . $name . "\t" . $f . "\t" . $b . "\n");
                    if ($exists && !isset($seen[$f])) {
                        $seen[$f] = 1;
                        $has = true;
                        if (!isset($z['by'][$name])) {
                            $z['by'][$name] = array('n' => 0, 'b' => 0);
                        }
                        $z['by'][$name]['n']++;
                        $z['by'][$name]['b'] += $b;
                        $z['files']++;
                        $z['bytes'] += $b;
                    }
                }
                if (!$has) {
                    $z['meta_only']++;
                }
                $any = true;
            }
            if ($any) {
                $z['att']++;
            }
        }
        fflush($fh);
        $s['szpos'] = ftell($fh);
        unset($rows);
    }
    fclose($fh);
    return true;
}

/** Obradi jednu skupinu redaka plana (isti attachment): premjesti/obrisi datoteke, pa azuriraj metapodatke. */
function umc_apply_size_group(array &$a, $id, array $lines)
{
    $up = umc_up();
    $realup = str_replace('\\', '/', (string) realpath($up));
    $q = umc_work() . '/quarantine';
    $names = array();
    $fail = array();
    foreach ($lines as $p) {
        $name = $p[1];
        $rel = $p[2];
        $bytes = (int) $p[3];
        $names[$name] = 1;
        $src = $up . '/' . $rel;
        if (strpos($rel, '..') !== false) {
            $a['err']++;
            $fail[$name] = 1;
            continue;
        }
        if (!is_file($src)) {
            if ($bytes > 0) {
                $a['miss']++;
            }
            continue;
        }
        $real = str_replace('\\', '/', (string) realpath($src));
        if (is_link($src) || $real === '' || strpos($real, $realup . '/') !== 0) {
            $a['err']++;
            $fail[$name] = 1;
            continue;
        }
        if ($a['method'] === 'delete') {
            $ok = @unlink($src);
            $act = 'SIZE_DELETE';
        } else {
            $dst = $q . '/' . $rel;
            wp_mkdir_p(dirname($dst));
            $ok = @rename($src, $dst);
            if (!$ok && @copy($src, $dst)) {
                $ok = @unlink($src);
            }
            $act = 'SIZE_QUARANTINE';
        }
        if ($ok) {
            $a['done']++;
            $a['bytes'] += $bytes;
            umc_log($act, $rel);
        } else {
            $a['err']++;
            $fail[$name] = 1;
        }
    }
    $meta = wp_get_attachment_metadata($id);
    if (is_array($meta) && !empty($meta['sizes']) && is_array($meta['sizes'])) {
        $bak = null;
        $changed = false;
        foreach ($names as $name => $unused) {
            if (isset($fail[$name]) || !isset($meta['sizes'][$name])) {
                continue;
            }
            if ($bak === null) {
                $bak = fopen(umc_work() . '/meta-backup.tsv', 'a');
            }
            fwrite($bak, $id . "\t" . $name . "\t" . base64_encode(serialize($meta['sizes'][$name])) . "\n");
            unset($meta['sizes'][$name]);
            $changed = true;
            $a['meta']++;
        }
        if ($bak) {
            fclose($bak);
        }
        if ($changed) {
            wp_update_attachment_metadata($id, $meta);
        }
    }
}

function umc_apply_sizes(array &$s, $deadline)
{
    $a =& $s['sapply'];
    $fh = @fopen(umc_work() . '/sizes.tsv', 'r');
    if (!$fh) {
        $a['phase'] = 'done';
        return;
    }
    fseek($fh, (int) $a['pos']);
    $group = array();
    $gid = 0;
    $n = 0;
    while (true) {
        $before = ftell($fh);
        $line = fgets($fh);
        if ($line === false) {
            if ($group) {
                umc_apply_size_group($a, $gid, $group);
            }
            $a['pos'] = ftell($fh);
            $a['phase'] = 'done';
            break;
        }
        $p = explode("\t", rtrim($line, "\r\n"));
        if (count($p) < 4) {
            continue;
        }
        $id = (int) $p[0];
        if ($group && $id !== $gid) {
            umc_apply_size_group($a, $gid, $group);
            $group = array();
            $a['pos'] = $before;
            if ((++$n % 10) === 0 && microtime(true) > $deadline) {
                fclose($fh);
                return;
            }
        }
        $gid = $id;
        $group[] = $p;
    }
    fclose($fh);
}

/** Vrati uklonjene unose velicina u metapodatke iz meta-backup.tsv. @return bool true kad je gotovo */
function umc_restore_meta(array &$s, $deadline)
{
    $f = umc_work() . '/meta-backup.tsv';
    if (!is_file($f)) {
        return true;
    }
    $fh = fopen($f, 'r');
    fseek($fh, (int) $s['rq']['mpos']);
    $cur = 0;
    $meta = null;
    $dirty = false;
    while (true) {
        $before = ftell($fh);
        $line = fgets($fh);
        if ($line === false) {
            break;
        }
        $p = explode("\t", rtrim($line, "\r\n"));
        if (count($p) < 3) {
            continue;
        }
        $id = (int) $p[0];
        if ($id !== $cur) {
            if ($dirty) {
                wp_update_attachment_metadata($cur, $meta);
                $dirty = false;
            }
            if (microtime(true) > $deadline) {
                $s['rq']['mpos'] = $before;
                fclose($fh);
                return false;
            }
            $cur = $id;
            $meta = wp_get_attachment_metadata($id);
        }
        if (is_array($meta)) {
            $ent = @unserialize(base64_decode($p[2]), array('allowed_classes' => false));
            if (is_array($ent) && !isset($meta['sizes'][$p[1]])) {
                $meta['sizes'][$p[1]] = $ent;
                $dirty = true;
                $s['rq']['m']++;
            }
        }
    }
    if ($dirty) {
        wp_update_attachment_metadata($cur, $meta);
    }
    fclose($fh);
    @rename($f, $f . '.restored');
    return true;
}

/* ------------------------------------------------------------------------- */
/* HTML                                                                      */
/* ------------------------------------------------------------------------- */

function umc_url($args = array())
{
    $self = strtok($_SERVER['REQUEST_URI'], '?');
    $args['n'] = wp_create_nonce('umc');
    return $self . '?' . http_build_query($args);
}

function umc_page($title, $body, $refresh = '')
{
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">';
    if ($refresh !== '') {
        echo '<meta http-equiv="refresh" content="1;url=' . esc_attr($refresh) . '">';
    }
    echo '<title>' . esc_html($title) . '</title><style>
body{font:14px/1.5 -apple-system,Segoe UI,Arial,sans-serif;max-width:1000px;margin:30px auto;padding:0 16px;color:#222}
h1{font-size:22px}h2{font-size:17px;margin-top:28px}
table{border-collapse:collapse;width:100%;margin:10px 0}td,th{border:1px solid #ddd;padding:5px 8px;text-align:left;vertical-align:top}
th{background:#f4f4f4}.r{text-align:right}.box{background:#f7f7f7;border:1px solid #ddd;padding:12px 16px;margin:14px 0}
.warn{background:#fff4e5;border-color:#f0b860}.ok{background:#e9f7ec;border-color:#8bc79a}
.btn{display:inline-block;background:#2271b1;color:#fff;border:0;padding:8px 14px;border-radius:3px;cursor:pointer;text-decoration:none;font-size:14px}
.btn.red{background:#b32d2e}.btn.gray{background:#666}code{background:#eee;padding:1px 4px}
.bar{background:#e5e5e5;height:14px;border-radius:7px;overflow:hidden}.bar i{display:block;height:14px;background:#2271b1}
</style></head><body><h1>' . esc_html($title) . '</h1>' . $body . '</body></html>';
    exit;
}

function umc_row($label, $count, $bytes)
{
    return '<tr><td>' . esc_html($label) . '</td><td class="r">' . number_format((int) $count, 0, ',', '.') . '</td><td class="r">' . umc_fmt_bytes($bytes) . '</td></tr>';
}

/* ------------------------------------------------------------------------- */
/* Kontroler                                                                 */
/* ------------------------------------------------------------------------- */

if (!is_user_logged_in()) {
    auth_redirect();
}
if (!current_user_can('manage_options')) {
    wp_die('Zabranjeno.', 403);
}
@ini_set('memory_limit', '512M');
@set_time_limit(0);
ignore_user_abort(true);
wp_suspend_cache_addition(true);

$cfg = umc_cfg();
$a = isset($_REQUEST['a']) ? (string) $_REQUEST['a'] : 'home';
if (!in_array($a, array('home', 'scan', 'report', 'csv', 'apply', 'applysizes', 'restore', 'purge'), true)) {
    $a = 'home';
}
if (!in_array($a, array('home', 'report', 'csv'), true)) {
    if (!isset($_REQUEST['n']) || !wp_verify_nonce($_REQUEST['n'], 'umc')) {
        wp_die('Nevazeci sigurnosni token. Vrati se na pocetnu stranicu i pokusaj ponovo.', 403);
    }
}
$deadline = microtime(true) + $cfg['time_budget'];
$s = umc_state_load();

/* ---- SCAN ---- */
if ($a === 'scan') {
    if (isset($_POST['start'])) {
        umc_work();
        @unlink(umc_work() . '/unused.tsv');
        @unlink(umc_work() . '/sizes.tsv');
        $live = umc_live_files();
        $s = array(
            'sizes' => !empty($_POST['sizes']), 'szcur' => 0, 'szpos' => 0, 'exact' => $live, 'live_n' => count($live), 'sapply' => null,
            'sz' => array('by' => array(), 'att' => 0, 'entries' => 0, 'retained' => 0, 'meta_only' => 0, 'files' => 0, 'bytes' => 0),
            'phase' => 'db', 't0' => time(), 'strict' => !empty($_POST['strict']), 'notrash' => !empty($_POST['notrash']),
            'steps' => umc_build_steps(!empty($_POST['notrash'])), 'si' => 0, 'cur' => 0, 'rows' => 0,
            'paths' => array(), 'ids' => array(),
            'ffiles' => array(), 'fi' => 0,
            'reg' => array(), 'reg_total' => 0, 'reg_nofile' => 0,
            'stack' => array(), 'upos' => 0, 'att_unused' => array(),
            'known' => umc_known_list(), 'apply' => null, 'rq' => array('n' => 0, 'skip' => 0, 'mpos' => 0, 'm' => 0),
            'st' => array('files' => 0, 'other' => 0, 'cand' => 0, 'cand_b' => 0, 'recent' => 0, 'unread' => 0,
                          'up' => 0, 'up_b' => 0, 'ui' => 0, 'ui_b' => 0, 'upar' => 0, 'upar_b' => 0,
                          'uO' => 0, 'uO_b' => 0, 'uA' => 0, 'uA_b' => 0),
        );
        umc_state_save($s);
    }
    if (!$s || $s['phase'] === 'done') {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    while (microtime(true) < $deadline && $s['phase'] !== 'done') {
        if ($s['phase'] === 'db') {
            if (umc_db_step($s, $deadline)) {
                $s['phase'] = 'files';
                $s['ffiles'] = umc_collect_scan_files();
            }
        } elseif ($s['phase'] === 'files') {
            if (umc_files_step($s, $deadline)) {
                $s['phase'] = 'registry';
            }
        } elseif ($s['phase'] === 'registry') {
            umc_registry($s);
            $s['phase'] = 'walk';
            $s['stack'] = array('');
        } elseif ($s['phase'] === 'walk') {
            if (umc_walk($s, $deadline)) {
                if (!empty($s['sizes'])) {
                    $s['phase'] = 'sizes';
                } else {
                    $s['phase'] = 'done';
                    $s['t1'] = time();
                }
            }
        } elseif ($s['phase'] === 'sizes') {
            if (umc_sizes_step($s, $deadline)) {
                $s['phase'] = 'done';
                $s['t1'] = time();
            }
        }
    }
    umc_state_save($s);
    if ($s['phase'] === 'done') {
        umc_page('Sken zavrsen', '<div class="box ok">Sken je gotov.</div><p><a class="btn" href="' . esc_url(umc_url(array('a' => 'report'))) . '">Pogledaj izvjestaj</a></p>');
    }
    $labels = array('db' => 'Citanje baze podataka', 'files' => 'Citanje datoteka teme/CSS', 'registry' => 'Registar attachmenta', 'walk' => 'Pregled uploads foldera', 'sizes' => 'Analiza velicina slika (metapodaci)');
    $detail = '';
    if ($s['phase'] === 'db') {
        $cur = isset($s['steps'][$s['si']]) ? $s['steps'][$s['si']]['t'] : '-';
        $detail = 'Tablica ' . ($s['si'] + 1) . '/' . count($s['steps']) . ' (' . esc_html($cur) . '), procitano redaka: ' . number_format($s['rows'], 0, ',', '.');
    } elseif ($s['phase'] === 'files') {
        $detail = 'Datoteka ' . $s['fi'] . '/' . count($s['ffiles']);
    } elseif ($s['phase'] === 'walk') {
        $detail = 'Pregledano datoteka: ' . number_format($s['st']['files'], 0, ',', '.') . ', u redu mapa: ' . count($s['stack']);
    } elseif ($s['phase'] === 'sizes') {
        $detail = 'Attachment ID do: ' . (int) $s['szcur'] . ', attachmenta s velicinama za uklanjanje: ' . number_format($s['sz']['att'], 0, ',', '.');
    }
    umc_page('Skeniranje...', '<div class="box"><b>' . esc_html($labels[$s['phase']]) . '</b><br>' . $detail . '</div><p>Ne zatvaraj stranicu, nastavlja se automatski.</p>', umc_url(array('a' => 'scan')));
}

/* ---- CSV ---- */
if ($a === 'csv') {
    $f = umc_work() . '/unused.tsv';
    if (!$s || $s['phase'] !== 'done' || !is_file($f)) {
        wp_die('Nema zavrsenog skena.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="unused-media-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('path', 'bytes', 'class', 'attachment_ids'));
    $in = fopen($f, 'r');
    while (($line = fgets($in)) !== false) {
        fputcsv($out, explode("\t", rtrim($line, "\r\n")));
    }
    exit;
}

/* ---- APPLY ---- */
if ($a === 'apply') {
    if (!$s || $s['phase'] !== 'done') {
        wp_die('Prvo napravi sken.');
    }
    if (isset($_POST['start'])) {
        $mode = isset($_POST['mode']) ? (string) $_POST['mode'] : 'orphans';
        $method = isset($_POST['method']) ? (string) $_POST['method'] : 'quarantine';
        if (!in_array($mode, array('orphans', 'attachments', 'all'), true)) {
            wp_die('Nevazeci mode.');
        }
        if ($method === 'delete' && (!isset($_POST['confirm']) || trim($_POST['confirm']) !== 'OBRISI')) {
            wp_die('Za trajno brisanje upisi OBRISI u polje za potvrdu.');
        }
        $db = !empty($_POST['db']) && $mode !== 'orphans';
        $s['apply'] = array(
            'mode' => $mode, 'method' => $method === 'delete' ? 'delete' : 'quarantine', 'db' => $db,
            'phase' => 'files', 'pos' => 0, 'done' => 0, 'bytes' => 0, 'miss' => 0, 'err' => 0,
            'dbids' => $db ? array_map('intval', array_keys($s['att_unused'])) : array(),
            'dbi' => 0, 'dbdone' => 0, 'dberr' => 0,
        );
        umc_state_save($s);
    }
    if (empty($s['apply'])) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    if ($s['apply']['phase'] === 'files') {
        umc_apply_files($s, $deadline);
    }
    if ($s['apply']['phase'] === 'db') {
        umc_apply_db($s, $deadline);
    }
    umc_state_save($s);
    $ap = $s['apply'];
    $sum = '<table><tr><td>Datoteka obradeno</td><td class="r">' . number_format($ap['done'], 0, ',', '.') . ' (' . umc_fmt_bytes($ap['bytes']) . ')</td></tr>'
        . '<tr><td>Nije pronadjeno / greske</td><td class="r">' . $ap['miss'] . ' / ' . $ap['err'] . '</td></tr>'
        . ($ap['db'] ? '<tr><td>Attachmenti obrisani iz baze</td><td class="r">' . $ap['dbdone'] . ' / ' . count($ap['dbids']) . ' (greske: ' . $ap['dberr'] . ')</td></tr>' : '')
        . '</table>';
    if ($ap['phase'] === 'done') {
        $msg = $ap['method'] === 'delete'
            ? 'Datoteke su trajno obrisane.'
            : 'Datoteke su premjestene u <code>wp-content/umc-work/quarantine</code>. Provjeri web stranicu; ako je sve u redu, mozes ih trajno obrisati (gumb na pocetnoj stranici).';
        umc_page('Primjena zavrsena', '<div class="box ok">' . $msg . '</div>' . $sum . '<p><a class="btn" href="' . esc_url(strtok($_SERVER['REQUEST_URI'], '?')) . '">Pocetna</a></p>');
    }
    umc_page('Primjena u tijeku...', '<div class="box"><b>Faza: ' . esc_html($ap['phase']) . '</b></div>' . $sum . '<p>Ne zatvaraj stranicu.</p>', umc_url(array('a' => 'apply')));
}

/* ---- APPLY SIZES ---- */
if ($a === 'applysizes') {
    if (!$s || $s['phase'] !== 'done' || empty($s['sizes'])) {
        wp_die('Prvo napravi sken s analizom velicina.');
    }
    if (isset($_POST['start'])) {
        $method = isset($_POST['method']) ? (string) $_POST['method'] : 'quarantine';
        if ($method === 'delete' && (!isset($_POST['confirm']) || trim($_POST['confirm']) !== 'OBRISI')) {
            wp_die('Za trajno brisanje upisi OBRISI u polje za potvrdu.');
        }
        $s['sapply'] = array(
            'method' => $method === 'delete' ? 'delete' : 'quarantine', 'phase' => 'files',
            'pos' => 0, 'done' => 0, 'bytes' => 0, 'miss' => 0, 'err' => 0, 'meta' => 0,
        );
        umc_state_save($s);
    }
    if (empty($s['sapply'])) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    if ($s['sapply']['phase'] === 'files') {
        umc_apply_sizes($s, $deadline);
    }
    umc_state_save($s);
    $ap = $s['sapply'];
    $sum = '<table><tr><td>Datoteka obradeno</td><td class="r">' . number_format($ap['done'], 0, ',', '.') . ' (' . umc_fmt_bytes($ap['bytes']) . ')</td></tr>'
        . '<tr><td>Nije pronadjeno / greske</td><td class="r">' . $ap['miss'] . ' / ' . $ap['err'] . '</td></tr>'
        . '<tr><td>Unosa uklonjeno iz metapodataka</td><td class="r">' . number_format($ap['meta'], 0, ',', '.') . '</td></tr></table>';
    if ($ap['phase'] === 'done') {
        $msg = $ap['method'] === 'delete'
            ? 'Velicine su trajno obrisane, a metapodaci azurirani.'
            : 'Velicine su premjestene u <code>wp-content/umc-work/quarantine</code>, a metapodaci azurirani. Provjeri web stranicu; ako je sve u redu, mozes ih trajno obrisati (gumb na pocetnoj stranici).';
        umc_page('Uklanjanje velicina zavrseno', '<div class="box ok">' . $msg . '</div>' . $sum . '<p><a class="btn" href="' . esc_url(strtok($_SERVER['REQUEST_URI'], '?')) . '">Pocetna</a></p>');
    }
    umc_page('Uklanjanje velicina u tijeku...', '<div class="box"><b>Faza: ' . esc_html($ap['phase']) . '</b></div>' . $sum . '<p>Ne zatvaraj stranicu.</p>', umc_url(array('a' => 'applysizes')));
}

/* ---- RESTORE / PURGE ---- */
if ($a === 'restore' || $a === 'purge') {
    if (!$s) {
        $s = array('rq' => array('n' => 0, 'skip' => 0, 'mpos' => 0, 'm' => 0));
    }
    if (isset($_POST['start']) || !isset($s['rq']['mpos'])) {
        $s['rq'] = array('n' => 0, 'skip' => 0, 'mpos' => 0, 'm' => 0);
        umc_state_save($s);
    }
    $finished = umc_quarantine_walk($s, $deadline, $a === 'purge');
    if ($finished && $a === 'restore') {
        $finished = umc_restore_meta($s, $deadline);
    }
    if ($finished && $a === 'purge') {
        @unlink(umc_work() . '/meta-backup.tsv');
    }
    umc_state_save($s);
    $what = $a === 'purge' ? 'Trajno obrisano' : 'Vraceno';
    $info = '<p>' . $what . ': ' . number_format($s['rq']['n'], 0, ',', '.') . ' datoteka' . ($s['rq']['skip'] ? ' (preskoceno jer vec postoje: ' . $s['rq']['skip'] . ')' : '') . ($a === 'restore' ? '; vraceno unosa u metapodacima: ' . number_format($s['rq']['m'], 0, ',', '.') : '') . '</p>';
    if ($finished) {
        umc_page($a === 'purge' ? 'Karantena obrisana' : 'Karantena vracena', '<div class="box ok">Gotovo.</div>' . $info . '<p><a class="btn" href="' . esc_url(strtok($_SERVER['REQUEST_URI'], '?')) . '">Pocetna</a></p>');
    }
    umc_page('U tijeku...', $info, umc_url(array('a' => $a)));
}

/* ---- REPORT ---- */
if ($a === 'report') {
    if (!$s || $s['phase'] !== 'done') {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $st = $s['st'];
    $unused_b = $st['uO_b'] + $st['uA_b'];
    $b = '<div class="box">Sken od ' . esc_html(date('d.m.Y H:i', $s['t0'])) . ' &middot; procitano redaka baze: ' . number_format($s['rows'], 0, ',', '.')
        . ' &middot; nacin: ' . ($s['strict'] ? '<b>strogi</b> (post_parent se ignorira)' : 'konzervativni (attachment vezan uz objavu/stranicu = koristi se)')
        . ' &middot; smece (trash): ' . (!empty($s['notrash']) ? '<b>ignorira se</b>' : 'racuna se kao referenca') . '</div>';
    $b .= '<h2>Sazetak (samo slike - kandidati)</h2><table><tr><th>Kategorija</th><th class="r">Datoteka</th><th class="r">Velicina</th></tr>';
    $b .= umc_row('Ukupno slika u uploads', $st['cand'], $st['cand_b']);
    $b .= umc_row('KORISTENO: referenca u tekstu/CSS-u/bazi (URL)', $st['up'], $st['up_b']);
    $b .= umc_row('KORISTENO: referenca preko ID-a (featured, galerija...)', $st['ui'], $st['ui_b']);
    $b .= umc_row('KORISTENO: attachment vezan uz objavu/stranicu', $st['upar'], $st['upar_b']);
    if ($st['recent']) {
        $b .= umc_row('Preskoceno (premlado)', $st['recent'], 0);
    }
    $b .= umc_row('NEKORISTENO - A: u Media Library, ali nigdje ne koristi', $st['uA'], $st['uA_b']);
    $b .= umc_row('NEKORISTENO - O: orphan (nije u Media Library)', $st['uO'], $st['uO_b']);
    $b .= '<tr><th>Ukupno nekoristeno</th><th class="r">' . number_format($st['uA'] + $st['uO'], 0, ',', '.') . '</th><th class="r">' . umc_fmt_bytes($unused_b) . '</th></tr></table>';
    $b .= '<p>Attachmenta u Media Library: ' . number_format($s['reg_total'], 0, ',', '.') . '; nekoristenih attachmenta: ' . number_format(count($s['att_unused']), 0, ',', '.') . '.</p>';

    if ($s['known']) {
        $tot = count($s['known']);
        $hit = 0;
        $rows = '';
        foreach ($s['known'] as $k) {
            if ($k['n'] === 0) {
                $status = 'nema na disku';
            } elseif ($k['u'] === $k['n']) {
                $status = 'NEKORISTENA (ok)';
                $hit++;
            } else {
                $status = 'KORISTENA (' . implode(',', array_keys($k['r'])) . ')';
            }
            if ($k['n'] === 0 || $k['u'] !== $k['n']) {
                $rows .= '<tr><td>' . esc_html($k['name']) . '</td><td>' . $k['n'] . '</td><td>' . esc_html($status) . '</td></tr>';
            }
        }
        $b .= '<h2>Provjera tvoje liste (known-unused.txt)</h2><div class="box ' . ($hit === $tot ? 'ok' : 'warn') . '">Prepoznato kao nekoristeno: <b>' . $hit . ' / ' . $tot . '</b></div>';
        if ($rows !== '') {
            $b .= '<p>Stavke koje NISU prepoznate kao nekoristene (provjeri zasto - vjerojatno postoji neka referenca):</p><table><tr><th>Datoteka</th><th>Datoteka na disku</th><th>Status</th></tr>' . $rows . '</table>';
        }
    }

    $list = array();
    $fh = @fopen(umc_work() . '/unused.tsv', 'r');
    if ($fh) {
        while (($line = fgets($fh)) !== false) {
            $p = explode("\t", rtrim($line, "\r\n"));
            if (count($p) >= 3) {
                $list[] = array($p[0], (int) $p[1], $p[2]);
            }
        }
        fclose($fh);
    }
    usort($list, function ($x, $y) {
        return $y[1] - $x[1];
    });
    $uurl = trailingslashit(wp_get_upload_dir()['baseurl']);
    $b .= '<h2>200 najvecih nekoristenih datoteka</h2><table><tr><th>Datoteka</th><th>Klasa</th><th class="r">Velicina</th></tr>';
    foreach (array_slice($list, 0, 200) as $r) {
        $b .= '<tr><td><a href="' . esc_url($uurl . $r[0]) . '" target="_blank" rel="noopener">' . esc_html($r[0]) . '</a></td><td>' . esc_html($r[2]) . '</td><td class="r">' . umc_fmt_bytes($r[1]) . '</td></tr>';
    }
    $b .= '</table><p><a class="btn gray" href="' . esc_url(umc_url(array('a' => 'csv'))) . '">Preuzmi cijeli popis (CSV)</a></p>';

    $b .= '<h2>Primjena</h2><form method="post" action="' . esc_url(strtok($_SERVER['REQUEST_URI'], '?')) . '" onsubmit="return confirm(\'Sigurno zelis nastaviti?\')">'
        . '<input type="hidden" name="a" value="apply"><input type="hidden" name="start" value="1"><input type="hidden" name="n" value="' . esc_attr(wp_create_nonce('umc')) . '">'
        . '<div class="box warn"><b>Prije primjene provjeri da imas potpuni backup (datoteke + baza).</b></div>'
        . '<p><b>Sto obraditi:</b><br>'
        . '<label><input type="radio" name="mode" value="orphans" checked> Samo orphane (O) &ndash; najsigurnije, baza se ne mijenja</label><br>'
        . '<label><input type="radio" name="mode" value="attachments"> Samo nekoristene attachmente (A)</label><br>'
        . '<label><input type="radio" name="mode" value="all"> Sve nekoristeno (O + A)</label></p>'
        . '<p><label><input type="checkbox" name="db" value="1"> Za klasu A obrisi i zapise iz Media Library (baza). Bez toga ostaju prazni zapisi u knjiznici.</label></p>'
        . '<p><b>Nacin:</b><br>'
        . '<label><input type="radio" name="method" value="quarantine" checked> Premjesti u karantenu (moze se vratiti)</label><br>'
        . '<label><input type="radio" name="method" value="delete"> Trajno obrisi odmah &ndash; upisi <code>OBRISI</code>: <input type="text" name="confirm" size="10"></label></p>'
        . '<p><button class="btn red" type="submit">Pokreni</button></p></form>';
    if (!empty($s['sizes'])) {
        $z = $s['sz'];
        $b .= '<h2>Velicine slika (faza B)</h2>';
        $b .= '<div class="box">Zadrzavaju se samo velicine: <code>' . esc_html(implode(', ', $cfg['keep_sizes'])) . '</code>. Sve ostalo se uklanja, osim datoteka koje se referenciraju po tocnom URL-u (baza, tema, zivi HTML). Zasticenih URL-ova: <b>' . number_format(count($s['exact']), 0, ',', '.') . '</b> (iz zivog HTML-a: ' . number_format((int) $s['live_n'], 0, ',', '.') . ').</div>';
        if (empty($s['live_n'])) {
            $b .= '<div class="box warn"><b>Upozorenje:</b> <code>live-files.txt</code> nije pronadjen pored skripte, pa zivi HTML nije uzet u obzir. Za sigurniju primjenu stavi ga pored skripte i ponovi sken.</div>';
        }
        uasort($z['by'], function ($x, $y) {
            return $y['b'] - $x['b'];
        });
        $b .= '<table><tr><th>Ime velicine</th><th class="r">Datoteka</th><th class="r">Velicina</th></tr>';
        foreach ($z['by'] as $nm => $v) {
            $b .= umc_row($nm, $v['n'], $v['b']);
        }
        $b .= '<tr><th>Ukupno se moze osloboditi</th><th class="r">' . number_format($z['files'], 0, ',', '.') . '</th><th class="r">' . umc_fmt_bytes($z['bytes']) . '</th></tr></table>';
        $b .= '<p>Attachmenta zahvaceno: ' . number_format($z['att'], 0, ',', '.') . '; unosa u metapodacima za uklanjanje: ' . number_format($z['entries'], 0, ',', '.') . ' (bez datoteke na disku: ' . number_format($z['meta_only'], 0, ',', '.') . '); unosa zadrzano zbog zastite ili dijeljene datoteke: ' . number_format($z['retained'], 0, ',', '.') . '.</p>';
        $b .= '<form method="post" action="' . esc_url(strtok($_SERVER['REQUEST_URI'], '?')) . '" onsubmit="return confirm(\'Sigurno zelis ukloniti velicine slika?\')">'
            . '<input type="hidden" name="a" value="applysizes"><input type="hidden" name="start" value="1"><input type="hidden" name="n" value="' . esc_attr(wp_create_nonce('umc')) . '">'
            . '<div class="box warn"><b>Prije primjene napravi backup baze</b> (mijenjaju se metapodaci attachmenta). Uklonjeni unosi spremaju se u <code>umc-work/meta-backup.tsv</code> i vracaju gumbom Vrati.</div>'
            . '<p><label><input type="radio" name="method" value="quarantine" checked> Premjesti u karantenu (moze se vratiti)</label><br>'
            . '<label><input type="radio" name="method" value="delete"> Trajno obrisi odmah &ndash; upisi <code>OBRISI</code>: <input type="text" name="confirm" size="10"></label></p>'
            . '<p><button class="btn red" type="submit">Ukloni velicine</button></p></form>';
    }
    umc_page('Izvjestaj o nekoristenim slikama', $b);
}

/* ---- HOME ---- */
$h = '<div class="box">Skripta prvo <b>samo analizira</b> (nista se ne mijenja). Brisanje se radi tek kad ga ti pokrenes na izvjestaju, i to prvo u <b>karantenu</b>.</div>';
if (is_dir(umc_work() . '/quarantine')) {
    $h .= '<div class="box warn"><b>Karantena nije prazna</b> (<code>wp-content/umc-work/quarantine</code>). Nakon sto provjeris stranicu, mozes je trajno obrisati ili vratiti.<br><br>'
        . '<form method="post" style="display:inline" onsubmit="return confirm(\'Vratiti sve datoteke iz karantene?\')"><input type="hidden" name="a" value="restore"><input type="hidden" name="start" value="1"><input type="hidden" name="n" value="' . esc_attr(wp_create_nonce('umc')) . '"><button class="btn gray">Vrati sve iz karantene</button></form> '
        . '<form method="post" style="display:inline" onsubmit="return confirm(\'TRAJNO obrisati sadrzaj karantene?\')"><input type="hidden" name="a" value="purge"><input type="hidden" name="start" value="1"><input type="hidden" name="n" value="' . esc_attr(wp_create_nonce('umc')) . '"><button class="btn red">Trajno obrisi karantenu</button></form></div>';
}
if ($s && isset($s['phase']) && $s['phase'] === 'done') {
    $h .= '<p>Zadnji sken: ' . esc_html(date('d.m.Y H:i', $s['t0'])) . ' &nbsp; <a class="btn" href="' . esc_url(umc_url(array('a' => 'report'))) . '">Izvjestaj</a></p>';
} elseif ($s && isset($s['phase'])) {
    $h .= '<p>Sken je prekinut u fazi <b>' . esc_html($s['phase']) . '</b>. <a class="btn" href="' . esc_url(umc_url(array('a' => 'scan'))) . '">Nastavi</a></p>';
}
$h .= '<h2>Novi sken</h2><form method="post" action="' . esc_url(strtok($_SERVER['REQUEST_URI'], '?')) . '">'
    . '<input type="hidden" name="a" value="scan"><input type="hidden" name="start" value="1"><input type="hidden" name="n" value="' . esc_attr(wp_create_nonce('umc')) . '">'
    . '<p><label><input type="checkbox" name="notrash" value="1"> Ignoriraj sadrzaj u smecu (trash): slike koristene samo u obrisanim stranicama/objavama smatraju se nekoristenima</label></p>'
    . '<p><label><input type="checkbox" name="sizes" value="1" checked> Analiziraj i velicine slika (faza B): pronadji suvisne generirane velicine (thumbnail-e) koje se ne koriste</label></p>'
    . '<p><label><input type="checkbox" name="strict" value="1"> Strogi nacin: ignoriraj da je attachment "vezan" uz objavu (vise nekoristenih, ali veci rizik)</label></p>'
    . '<p><button class="btn" type="submit">Pokreni sken</button></p></form>'
    . '<p style="color:#666">Mape koje se ne diraju: ' . esc_html(implode(', ', $cfg['protected_dirs'])) . '. Kandidati: ' . esc_html(implode(', ', $cfg['candidate_ext'])) . '.'
    . (is_readable($cfg['known_list']) ? ' Pronadjen known-unused.txt.' : ' known-unused.txt nije pronadjen (opcionalno).') . '</p>';
umc_page('Unused Media Cleanup', $h);
