<?php
declare(strict_types=1);

const VERSION = '4.1.0-render-webhook';
const TG_API = 'https://api.telegram.org';
const LINE = '━━━━━━━━━━━━━━━━━━';
const DLINE = '══════════════════';
const SPIN = ['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'];
const EN_MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const BN_MONTHS = ['জানুয়ারি','ফেব্রুয়ারি','মার্চ','এপ্রিল','মে','জুন','জুলাই','আগস্ট','সেপ্টেম্বর','অক্টোবর','নভেম্বর','ডিসেম্বর'];

const DEFAULT_SETTINGS = [
    'brand' => 'নিবন্ধন যাচাই বট',
    'support' => '',
    'welcome_bn' => '',
    'welcome_en' => '',
    'notify_new' => '1',
    'maint' => '0',
    'maint_msg_bn' => 'সিস্টেম এখন রক্ষণাবেক্ষণে আছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।',
    'maint_msg_en' => 'The system is under maintenance. Please try again shortly.',
    'rate' => '20',
    'broadcast_log' => '1',
];

const SEED_BUTTONS = [
    ['জন্ম নিবন্ধন যাচাই','Birth Verification','🍼','https://birth-verify-api.tzs.workers.dev/birth?brn={number}&dob={date}',1,1],
    ['মৃত্যু নিবন্ধন যাচাই','Death Verification','⚰️','https://death-verify-api.devbd.workers.dev/death?drn={number}&dod={date}',1,2],
];

const SKIP_KEYS = [
    'success','status','code','message','error','errors','msg','ok','developerinfo','dev',
    'auth','tg','telegram','channel','meta','links','allfields','additionalfields',
    'recordsource','record','timestamp','time',
];

function e(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function bold(mixed $v): string { return '<b>'.e($v).'</b>'; }
function ital(mixed $v): string { return '<i>'.e($v).'</i>'; }
function mono(mixed $v): string { return '<code>'.e($v).'</code>'; }
function tr(string $lang, string $bn, string $en): string { return $lang === 'en' ? $en : $bn; }
function nowms(): int { return (int)round(microtime(true) * 1000); }
function trunc(mixed $v, int $n): string {
    $s = (string)($v ?? '');
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s;
}
function bnNum(mixed $v): string {
    return strtr((string)($v ?? ''), ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']);
}
function digitize(mixed $v): string {
    return strtr((string)($v ?? ''), ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
}
function localNum(string $lang, mixed $v): string { return $lang === 'en' ? (string)($v ?? '') : bnNum($v); }
function kb(array $rows): array {
    return ['inline_keyboard'=>array_values(array_filter($rows, static fn($row) => is_array($row) && count($row)))];
}
function btn(string $text, string $data): array { return ['text'=>$text,'callback_data'=>$data]; }
function urlBtn(string $text, string $url): array { return ['text'=>$text,'url'=>$url]; }
function cfg(): array { return $GLOBALS['_cfg'] ?? []; }
function envv(string $key, string $default=''): string {
    $v=getenv($key);
    return ($v===false || $v==='') ? $default : trim((string)$v);
}
function adminChatId(): string {
    return (string)(cfg()['admin_chat_id'] ?? (cfg()['admin_ids'][0] ?? ''));
}
function isAdminContext(string $uid, string $chat): bool {
    return isAdmin($uid) && $chat!=='' && hash_equals(adminChatId(), $chat);
}
function baseUrl(): string {
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = $proto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    return ($https ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').($_SERVER['SCRIPT_NAME'] ?? '/bot.php');
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $c = cfg();
    $pdo = new PDO(
        'mysql:host='.$c['host'].';dbname='.$c['db'].';charset=utf8mb4',
        $c['user'],
        $c['pass'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT=>5]
    );
    return $pdo;
}
function q(string $sql, array $args=[]): PDOStatement { $s=db()->prepare($sql); $s->execute($args); return $s; }
function one(string $sql, array $args=[]): ?array { $r=q($sql,$args)->fetch(); return $r ?: null; }
function all(string $sql, array $args=[]): array { return q($sql,$args)->fetchAll(); }

function settingsAll(): array {
    if (isset($GLOBALS['_settings_cache']) && is_array($GLOBALS['_settings_cache'])) return $GLOBALS['_settings_cache'];
    $settings = DEFAULT_SETTINGS;
    foreach (all('SELECT `key`,`value` FROM settings') as $row) $settings[(string)$row['key']] = (string)$row['value'];
    return $GLOBALS['_settings_cache'] = $settings;
}
function setting(string $key, string $fallback=''): string {
    $settings = settingsAll();
    return (string)($settings[$key] ?? $fallback);
}
function clearSettingsCache(): void { unset($GLOBALS['_settings_cache']); }
function setSetting(string $key, string $value): void {
    q('INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)', [$key,$value]);
    clearSettingsCache();
}
function isAdmin(string $id): bool {
    $owners = array_map('strval', (array)(cfg()['admin_ids'] ?? []));
    return in_array($id, $owners, true) || (bool)one('SELECT id FROM admins WHERE id=?', [$id]);
}
function adminIds(): array {
    $ids = array_map('strval', (array)(cfg()['admin_ids'] ?? []));
    foreach (all('SELECT id FROM admins') as $row) $ids[] = (string)$row['id'];
    return array_values(array_unique($ids));
}
function logAction(string $uid, string $action, string $detail=''): void {
    q('INSERT INTO logs(at,user_id,action,detail) VALUES(?,?,?,?)', [nowms(),$uid,$action,trunc($detail,300)]);
}

function schema(): void {
    $sql = [
        'CREATE TABLE IF NOT EXISTS users(id VARCHAR(64) PRIMARY KEY,name VARCHAR(255),username VARCHAR(255),lang VARCHAR(5) DEFAULT "bn",blocked TINYINT DEFAULT 0,created_at BIGINT,last_seen BIGINT,checks INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS admins(id VARCHAR(64) PRIMARY KEY,added_at BIGINT,added_by VARCHAR(64)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS buttons(id INT PRIMARY KEY AUTO_INCREMENT,title_bn VARCHAR(255),title_en VARCHAR(255),emoji VARCHAR(32) DEFAULT "",url TEXT NOT NULL,needs_date TINYINT DEFAULT 1,active TINYINT DEFAULT 1,sort INT DEFAULT 0,note_bn TEXT,note_en TEXT,created_at BIGINT,created_by VARCHAR(64)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS sessions(id VARCHAR(64) PRIMARY KEY,mid VARCHAR(32),step VARCHAR(64),data LONGTEXT,updated BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS settings(`key` VARCHAR(80) PRIMARY KEY,`value` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS checks(id BIGINT PRIMARY KEY AUTO_INCREMENT,user_id VARCHAR(64),button_id INT,title VARCHAR(255),number VARCHAR(64),date VARCHAR(32),ok TINYINT,created_at BIGINT,INDEX(user_id,created_at),INDEX(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS logs(id BIGINT PRIMARY KEY AUTO_INCREMENT,at BIGINT,user_id VARCHAR(64),action VARCHAR(80),detail TEXT,INDEX(at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS broadcasts(id BIGINT PRIMARY KEY AUTO_INCREMENT,admin_id VARCHAR(64),body TEXT,status VARCHAR(30),sent INT DEFAULT 0,failed INT DEFAULT 0,total INT DEFAULT 0,created_at BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ];
    foreach ($sql as $statement) q($statement);
    foreach (DEFAULT_SETTINGS as $key=>$value) q('INSERT IGNORE INTO settings(`key`,`value`) VALUES(?,?)', [$key,$value]);
    if ((int)(one('SELECT COUNT(*) n FROM buttons')['n'] ?? 0) === 0) {
        foreach (SEED_BUTTONS as $seed) {
            q('INSERT INTO buttons(title_bn,title_en,emoji,url,needs_date,active,sort,created_at) VALUES(?,?,?,?,?,?,?,?)',
                [$seed[0],$seed[1],$seed[2],$seed[3],$seed[4],1,$seed[5],nowms()]);
        }
    }
    clearSettingsCache();
}

function tg(string $method, array $payload=[]): array {
    $token = (string)(cfg()['token'] ?? '');
    $ch = curl_init(TG_API.'/bot'.$token.'/'.$method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>35,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : ['ok'=>false,'description'=>$err ?: 'Invalid Telegram response'];
}
function sendMsg(string $chat, string $text, ?array $markup=null): ?int {
    $payload = ['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true];
    if ($markup !== null) $payload['reply_markup'] = $markup;
    $r = tg('sendMessage', $payload);
    return isset($r['result']['message_id']) ? (int)$r['result']['message_id'] : null;
}
function editOnly(string $chat, int $mid, string $text, ?array $markup=null): array {
    $payload = ['chat_id'=>$chat,'message_id'=>$mid,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true,'reply_markup'=>$markup ?? kb([])];
    return tg('editMessageText', $payload);
}
function editMsg(string $chat, int $mid, string $text, ?array $markup=null): ?int {
    $r = editOnly($chat,$mid,$text,$markup);
    if (!empty($r['ok']) || stripos((string)($r['description'] ?? ''), 'not modified') !== false) return $mid;
    return sendMsg($chat,$text,$markup);
}
function answer(string $id, ?string $text=null): void {
    if ($id === '') return;
    $payload = ['callback_query_id'=>$id];
    if ($text !== null && $text !== '') $payload['text'] = $text;
    tg('answerCallbackQuery',$payload);
}

function supportUrl(): string {
    $support = trim(setting('support'));
    if ($support === '') return '';
    return preg_match('~^https?://~i',$support) ? $support : 'https://t.me/'.ltrim($support,'@');
}
function supportRow(string $lang): ?array {
    $url = supportUrl();
    return $url === '' ? null : [urlBtn(tr($lang,'💬 সাপোর্ট','💬 Support'),$url)];
}
function dayShort(int $ms): string { return gmdate('j M y',(int)floor($ms/1000)+6*3600); }
function hhmm(int $ms): string { return gmdate('H:i',(int)floor($ms/1000)+6*3600); }

function isoDate(int $year, int $month, int $day): ?string {
    if ($year < 1 || !checkdate($month,$day,$year)) return null;
    return sprintf('%04d-%02d-%02d',$year,$month,$day);
}
function parseDate(string $input): ?string {
    $s = trim((string)preg_replace('/\s+/u',' ',digitize($input)));
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/',$s,$m)) return isoDate((int)$m[1],(int)$m[2],(int)$m[3]);
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/',$s,$m)) return isoDate((int)$m[3],(int)$m[2],(int)$m[1]);
    if (preg_match('/^(\d{1,2})\s+([A-Za-z\x{0980}-\x{09FF}]+),?\s+(\d{4})$/u',$s,$m)) {
        $month = null;
        $needle = strtolower(substr($m[2],0,3));
        foreach (EN_MONTHS as $i=>$name) if (str_starts_with(strtolower($name),$needle)) { $month=$i+1; break; }
        if ($month === null) foreach (BN_MONTHS as $i=>$name) if ($name === $m[2]) { $month=$i+1; break; }
        return $month === null ? null : isoDate((int)$m[3],$month,(int)$m[1]);
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/',$s,$m)) return isoDate((int)$m[1],(int)$m[2],(int)$m[3]);
    return null;
}
function prettyDate(string $iso): string {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$iso,$m)) return (int)$m[3].' '.(EN_MONTHS[(int)$m[2]-1] ?? '').' '.$m[1];
    return $iso;
}
function bnDate(mixed $value): string {
    $s = trim((string)($value ?? ''));
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/',$s,$m)) {
        foreach (EN_MONTHS as $i=>$name) if (strcasecmp($name,$m[2])===0) return bnNum($m[1]).' '.BN_MONTHS[$i].' '.bnNum($m[3]);
    }
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/',$s,$m)) {
        return trim(bnNum($m[3]).' '.(BN_MONTHS[(int)$m[2]-1] ?? '').' '.bnNum($m[1]));
    }
    return $s;
}
function isDateLike(mixed $value): bool {
    return is_string($value) && (bool)(preg_match('/^\d{1,2}\s+[A-Za-z]+\s+\d{4}$/',trim($value)) || preg_match('/^\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}/',trim($value)));
}
function normalizeKey(string $key): string { return strtolower((string)preg_replace('/[^a-z0-9]/','',$key)); }

function labels(): array {
    static $map = [
        'brn'=>['জন্ম নিবন্ধন নম্বর','Birth Registration No'],'birthregistrationnumber'=>['জন্ম নিবন্ধন নম্বর','Birth Registration No'],
        'registrationnumber'=>['নিবন্ধন নম্বর','Registration No'],'drn'=>['মৃত্যু নিবন্ধন নম্বর','Death Registration No'],
        'deathregistrationnumber'=>['মৃত্যু নিবন্ধন নম্বর','Death Registration No'],'nid'=>['জাতীয় পরিচয়পত্র নম্বর','NID No'],
        'name'=>['নাম','Name'],'fullname'=>['পূর্ণ নাম','Full Name'],'personname'=>['ব্যক্তির নাম','Person Name'],
        'namebangla'=>['নাম','Name (Bangla)'],'nameenglish'=>['নাম (ইংরেজি)','Name (English)'],
        'dateofbirth'=>['জন্ম তারিখ','Date of Birth'],'dob'=>['জন্ম তারিখ','Date of Birth'],'birthdate'=>['জন্ম তারিখ','Date of Birth'],
        'dateofdeath'=>['মৃত্যু তারিখ','Date of Death'],'dod'=>['মৃত্যু তারিখ','Date of Death'],'deathdate'=>['মৃত্যু তারিখ','Date of Death'],
        'age'=>['বয়স','Age'],'gender'=>['লিঙ্গ','Gender'],'sex'=>['লিঙ্গ','Gender'],'genderenglish'=>['লিঙ্গ','Gender'],
        'placeofbirth'=>['জন্মস্থান','Place of Birth'],'birthplace'=>['জন্মস্থান','Place of Birth'],'birthplacebangla'=>['জন্মস্থান','Place of Birth'],
        'placeofdeath'=>['মৃত্যুস্থান','Place of Death'],'deathplace'=>['মৃত্যুস্থান','Place of Death'],
        'fathername'=>['পিতার নাম',"Father's Name"],'fathernamebangla'=>['পিতার নাম',"Father's Name"],'father'=>['পিতার নাম',"Father's Name"],
        'mothername'=>['মাতার নাম',"Mother's Name"],'mothernamebangla'=>['মাতার নাম',"Mother's Name"],'mother'=>['মাতার নাম',"Mother's Name"],
        'fathernationality'=>['পিতার জাতীয়তা',"Father's Nationality"],'mothernationality'=>['মাতার জাতীয়তা',"Mother's Nationality"],
        'nationality'=>['জাতীয়তা','Nationality'],'religion'=>['ধর্ম','Religion'],'occupation'=>['পেশা','Occupation'],
        'maritalstatus'=>['বৈবাহিক অবস্থা','Marital Status'],'spouse'=>['স্বামী/স্ত্রী','Spouse'],
        'registrationdate'=>['নিবন্ধন তারিখ','Registration Date'],'dateofregistration'=>['নিবন্ধন তারিখ','Registration Date'],
        'issuedate'=>['সনদ ইস্যুর তারিখ','Issuance Date'],'registrationoffice'=>['নিবন্ধন কার্যালয়','Registration Office'],
        'registerofficeenglish'=>['নিবন্ধন কার্যালয়','Registration Office'],'registerofficename'=>['নিবন্ধন কার্যালয়','Registration Office'],
        'unionoroffice'=>['ইউনিয়ন / কার্যালয়','Union / Office'],'union'=>['ইউনিয়ন','Union'],'upazila'=>['উপজেলা','Upazila'],
        'district'=>['জেলা','District'],'division'=>['বিভাগ','Division'],'village'=>['গ্রাম','Village'],'postoffice'=>['ডাকঘর','Post Office'],
        'ward'=>['ওয়ার্ড','Ward'],'address'=>['ঠিকানা','Address'],'presentaddress'=>['বর্তমান ঠিকানা','Present Address'],
        'permanentaddress'=>['স্থায়ী ঠিকানা','Permanent Address'],'date'=>['তারিখ','Date'],
    ];
    return $map;
}
function labelOf(string $key, string $lang): string {
    $map=labels(); $hit=$map[normalizeKey($key)] ?? null;
    if ($hit) return $hit[$lang==='en'?1:0];
    $words=trim((string)preg_replace('/([a-z])([A-Z])/','$1 $2',str_replace(['_','-'],' ',$key)));
    return $words !== '' ? ucfirst($words) : tr($lang,'তথ্য','Info');
}
function prettyValue(string $key, mixed $value, string $lang): string {
    if (is_bool($value)) return tr($lang,$value?'হ্যাঁ':'না',$value?'Yes':'No');
    if (is_int($value)||is_float($value)) return e(localNum($lang,$value));
    $s=trim((string)($value ?? ''));
    if ($s==='') return '';
    if (preg_match('/^\d{6,}$/',$s)) return mono($s);
    if (isDateLike($s)) return bold($lang==='en'?$s:bnDate($s));
    if (in_array(normalizeKey($key),['gender','sex','genderenglish'],true)) {
        $map=['MALE'=>['পুরুষ','Male'],'FEMALE'=>['মহিলা','Female'],'M'=>['পুরুষ','Male'],'F'=>['মহিলা','Female'],'O'=>['অন্যান্য','Other'],'OTHER'=>['অন্যান্য','Other']];
        $g=$map[strtoupper($s)] ?? null;
        if ($g) return bold($g[$lang==='en'?1:0]);
    }
    return $lang==='en' ? ital($s) : e($s);
}
function apiOk(array $json): bool {
    if (($json['success']??null)===false || ($json['status']??null)===false) return false;
    if (($json['success']??null)===true || ($json['status']??null)===true || (int)($json['code']??0)===200) return true;
    foreach (array_keys($json) as $key) if (!in_array(normalizeKey((string)$key),SKIP_KEYS,true)) return true;
    return false;
}
function buildUrl(string $template, string $number, ?string $date): string {
    return apiUrl($template,$number,$date);
}

function apiUrl(string $template, string $number, ?string $date): string {
    return str_ireplace(
        ['{number}','{brn}','{drn}','{nid}','{n}','{date}','{dob}','{dod}','{d}'],
        [rawurlencode($number),rawurlencode($number),rawurlencode($number),rawurlencode($number),rawurlencode($number),rawurlencode((string)$date),rawurlencode((string)$date),rawurlencode((string)$date),rawurlencode((string)$date)],
        $template
    );
}

function buttons(bool $active=true): array { return all('SELECT * FROM buttons '.($active?'WHERE active=1 ':'').'ORDER BY sort ASC,id ASC'); }
function getButton(string|int $id): ?array { return one('SELECT * FROM buttons WHERE id=?',[(int)$id]); }

function notifyNewUser(array $user): void {
    if (setting('notify_new') !== '1') return;
    $total=(int)(one('SELECT COUNT(*) n FROM users')['n']??0);
    $text="🆕 ".bold('নতুন ইউজার যোগ দিয়েছে')."\n".LINE."\n👤 ".e($user['name']).(!empty($user['username'])?' · @'.e($user['username']):'')."\n🆔 ".mono($user['id'])."\n📅 ".e(dayShort((int)$user['created_at'])).' · মোট ইউজার: '.bold(bnNum($total));
    foreach (adminIds() as $id) sendMsg($id,$text,kb([[btn('🚫 '.tr('bn','ব্যান','Ban'),'usr:block:'.$user['id']),btn('👤 '.tr('bn','দেখুন','View'),'usr:view:'.$user['id'])],[btn('🛠 এডমিন প্যানেল','adm')]]));
}
function loadState(string $uid, array $from): array {
    $user=one('SELECT * FROM users WHERE id=?',[$uid]);
    $name=trim((string)($from['first_name']??'').' '.(string)($from['last_name']??'')) ?: (!empty($from['username'])?'@'.$from['username']:$uid);
    $username=(string)($from['username']??''); $now=nowms();
    if (!$user) {
        $ins=q('INSERT IGNORE INTO users(id,name,username,lang,blocked,created_at,last_seen,checks) VALUES(?,?,?,"bn",0,?,?,0)',[$uid,$name,$username,$now,$now]);
        $user=one('SELECT * FROM users WHERE id=?',[$uid]) ?? ['id'=>$uid,'name'=>$name,'username'=>$username,'lang'=>'bn','blocked'=>0,'created_at'=>$now,'last_seen'=>$now,'checks'=>0];
        if ($ins->rowCount()>0) notifyNewUser($user);
    } else {
        q('UPDATE users SET name=?,username=?,last_seen=? WHERE id=?',[$name,$username,$now,$uid]);
        $user['name']=$name; $user['username']=$username; $user['last_seen']=$now;
    }
    $row=one('SELECT step,data FROM sessions WHERE id=?',[$uid]);
    $data=[];
    if (!empty($row['data'])) $data=json_decode((string)$row['data'],true) ?: [];
    return [$user,['step'=>$row['step']??null,'data'=>$data]];
}
function saveState(string $uid, array $state): void {
    q('INSERT INTO sessions(id,mid,step,data,updated) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE mid=VALUES(mid),step=VALUES(step),data=VALUES(data),updated=VALUES(updated)',[
        $uid,$state['data']['panel']??null,$state['step']??null,json_encode($state['data']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),nowms()
    ]);
}
function present(array $view, string $chat, array &$state, string $kind, bool $fresh=false): ?int {
    $slot=(int)($state['data'][$kind]??0);
    $tapped=(int)($GLOBALS['_tap_mid']??0);
    $target=$fresh?0:($tapped?:$slot);
    $new=$target?editMsg($chat,$target,$view['text'],$view['markup']??null):sendMsg($chat,$view['text'],$view['markup']??null);
    if ($new) $state['data'][$kind]=$new; else unset($state['data'][$kind]);
    saveState((string)($GLOBALS['_uid']??''),$state);
    return $new;
}
function clearAllTargets(array &$state): void {
    foreach (['panel','flow','aux','adm'] as $key) unset($state['data'][$key]);
    foreach (['bid','number','langOrigin'] as $key) unset($state['data'][$key]);
    $state['step']=null;
}

function panelView(string $lang,string $name,bool $admin): array {
    $services=buttons(); $rows=[];
    for ($i=0;$i<count($services);$i+=2) {
        $row=[];
        foreach (array_slice($services,$i,2) as $b) $row[]=btn(($b['emoji']?:'🔎').' '.trunc($lang==='en'?$b['title_en']:$b['title_bn'],24),'svc:'.$b['id']);
        $rows[]=$row;
    }
    $rows[]=[btn(tr($lang,'🧾  হিস্টোরি','🧾  History'),'hist')];
    $rows[]=[btn(tr($lang,'ℹ️  সহায়তা','ℹ️  Help'),'help'),btn($lang==='en'?'🇧🇩  বাংলা':'🌐  English','lang')];
    if ($admin) $rows[]=[btn(tr($lang,'🛠  এডমিন প্যানেল','🛠  Admin Panel'),'adm')];
    if ($support=supportRow($lang)) $rows[]=$support;
    $welcome=setting($lang==='en'?'welcome_en':'welcome_bn') ?: tr($lang,"আস‌সালামু আলাইকুম, $name 👋","Hello, $name 👋");
    $list=array_map(static fn($b)=>($b['emoji']?:'🔎').' '.e(trunc($lang==='en'?$b['title_en']:$b['title_bn'],32)),array_slice($services,0,6));
    return ['text'=>"🇧🇩 ".bold(setting('brand',DEFAULT_SETTINGS['brand']))."\n".ital(tr($lang,'জন্ম ও মৃত্যু নিবন্ধন যাচাই সেবা','Birth & Death Registration Verification'))."\n".LINE."\n".$welcome."\n\n".bold(tr($lang,'উপলব্ধ সেবা','Available services'))."\n".implode("\n",$list)."\n".LINE."\n".ital(tr($lang,'⚡ ইনস্ট্যান্ট যাচাই  ·  🔒 সরকারি ডাটাবেস  ·  🕒 ২৪/৭','⚡ Instant verification  ·  🔒 Official database  ·  🕒 24/7')),'markup'=>kb($rows)];
}
function langView(string $lang): array {
    return ['text'=>"🌐 ".bold(tr($lang,'ভাষা নির্বাচন','SELECT LANGUAGE'))."\n".LINE."\n".tr($lang,'বর্তমান ভাষা','Current language').': '.bold($lang==='en'?'English 🇬🇧':'বাংলা 🇧🇩')."\n\n".ital(tr($lang,'আপনার পছন্দের ভাষাটি বেছে নিন।','Pick your preferred language.')),
        'markup'=>kb([[btn($lang==='bn'?'✅ 🇧🇩 বাংলা':'🇧🇩 বাংলা','lang:bn'),btn($lang==='en'?'✅ 🇬🇧 English':'🇬🇧 English','lang:en')],[btn(tr($lang,'🔙 পেছনে','🔙 Back'),'lang:back')]])];
}
function flowAsk(string $lang,array $b): array {
    return ['text'=>($b['emoji']?:'🔎').' '.bold($lang==='en'?$b['title_en']:$b['title_bn'])."\n".LINE."\n📝 ".tr($lang,'১৭ ডিজিটের নিবন্ধন নম্বর পাঠান।','Send the 17-digit registration number.')."\n\n".ital((int)$b['needs_date']?tr($lang,'💡 নম্বর ও তারিখ একসাথে পাঠাতে পারেন।','💡 You can send number + date together.'):tr($lang,'💡 শুধু নম্বরটি পাঠান।','💡 Send the number only.')),'markup'=>kb([[btn(tr($lang,'❌ বাতিল','❌ Cancel'),'f:cancel')]])];
}
function dateAsk(string $lang,array $b,string $number): array {
    return ['text'=>($b['emoji']?:'🔎').' '.bold($lang==='en'?$b['title_en']:$b['title_bn'])."\n".LINE."\n✅ ".tr($lang,'নম্বর পাওয়া গেছে','Number received').': '.mono($number)."\n\n📅 ".tr($lang,'এখন তারিখ পাঠান।','Now send the date.')."\n".ital(tr($lang,'ফরম্যাট','Format').': 2018-10-12'),'markup'=>kb([[btn(tr($lang,'🔙 পেছনে','🔙 Back'),'f:back'),btn(tr($lang,'❌ বাতিল','❌ Cancel'),'f:cancel')]])];
}
function closeView(string $lang,string $title): array {
    return ['text'=>$title."\n".LINE."\n".ital(tr($lang,'আবার শুরু করতে হোমে ফিরুন।','Press Home to start again.')),'markup'=>kb([[btn(tr($lang,'🏠 হোম','🏠 Home'),'panel')]])];
}
function helpView(string $lang): array {
    $rows=[[btn(tr($lang,'🧾 হিস্টোরি','🧾 History'),'hist'),btn(tr($lang,'🏠 হোম','🏠 Home'),'panel')],[btn(tr($lang,'✖️ বন্ধ করুন','✖️ Close'),'close')]];
    if ($support=supportRow($lang)) array_splice($rows,2,0,[$support]);
    return ['text'=>"ℹ️ ".bold(tr($lang,'ব্যবহার নির্দেশিকা','HOW TO USE'))."\n".LINE."\n".bold('১।').' '.tr($lang,'হোম থেকে সেবা বেছে নিন','Pick a service from Home')."\n".bold('২।').' '.tr($lang,'১৭ ডিজিটের নম্বর পাঠান','Send the 17-digit number')."\n".bold('৩।').' '.tr($lang,'তারিখ পাঠান (প্রযোজ্য ক্ষেত্রে)','Send the date (if required)')."\n".bold('৪।').' '.tr($lang,'তাৎক্ষণিক ফলাফল পান','Get the result instantly')."\n\n📌 ".tr($lang,'কমান্ড সমূহ','Commands').': '.mono('/start').' · '.mono('/history').' · '.mono('/lang').' · '.mono('/help')."\n\n".ital(tr($lang,'💡 নম্বর ও তারিখ একসাথে পাঠালেও কাজ করে।','💡 You can send number and date together.')),'markup'=>kb($rows)];
}
function supportView(string $lang): array {
    $url=supportUrl(); $rows=[];
    if ($url!=='') $rows[]=[urlBtn(tr($lang,'💬 সাপোর্ট খুলুন','💬 Open support'),$url)];
    $rows[]=[btn(tr($lang,'🏠 হোম','🏠 Home'),'panel'),btn(tr($lang,'✖️ বন্ধ','✖️ Close'),'close')];
    return ['text'=>"💬 ".bold(tr($lang,'সাপোর্ট','SUPPORT'))."\n".LINE."\n".($url!==''?tr($lang,'সহায়তার জন্য নিচের বাটনে চাপ দিন।','Use the button below to contact support.'):tr($lang,'সাপোর্ট লিংক এখনো সেট করা হয়নি।','A support link has not been configured.')),'markup'=>kb($rows)];
}
function idView(string $lang,string $uid): array {
    return ['text'=>"🆔 ".bold(tr($lang,'আপনার টেলিগ্রাম আইডি','Your Telegram ID'))."\n".LINE."\n".mono($uid)."\n\n".ital(tr($lang,'এডমিন যোগ করার সময় এই আইডি লাগবে।','This ID is needed to be added as admin.')),'markup'=>kb([[btn(tr($lang,'🔙 ব্যাক','🔙 Back'),'panel'),btn(tr($lang,'✖️ বন্ধ','✖️ Close'),'close')]])];
}
function historyView(string $lang,string $uid): array {
    $rows=all('SELECT title,number,date,ok,created_at FROM checks WHERE user_id=? ORDER BY id DESC LIMIT 7',[$uid]);
    $body=$rows?implode("\n\n",array_map(static fn($r)=>($r['ok']?'✅':'❌').' '.e(trunc($r['title'],28))."\n　".mono($r['number']).($r['date']?' · '.e(prettyDate($r['date'])):'').' · '.e(hhmm((int)$r['created_at'])),$rows)):ital(tr($lang,'এখনো কোনো যাচাই করা হয়নি।','No verification done yet.'));
    return ['text'=>"🧾 ".bold(tr($lang,'আমার হিস্টোরি','MY HISTORY'))."\n".LINE."\n$body\n".LINE."\n".ital(tr($lang,'সর্বশেষ '.count($rows).' টি যাচাই','Last '.count($rows).' checks')),'markup'=>kb([[btn(tr($lang,'🔄 রিফ্রেশ','🔄 Refresh'),'hist'),btn(tr($lang,'✖️ বন্ধ','✖️ Close'),'close')],[btn(tr($lang,'🔙 ব্যাক','🔙 Back'),'panel')]])];
}
function pickServiceView(string $lang,string $number,string $date,array $services): array {
    $rows=[];
    foreach ($services as $b) $rows[]=[btn(($b['emoji']?:'🔎').' '.($lang==='en'?$b['title_en']:$b['title_bn']),'run:'.$b['id'].':'.$number.':'.$date)];
    $rows[]=[btn(tr($lang,'✖️ বন্ধ','✖️ Close'),'f:close')];
    return ['text'=>"🔎 ".bold(tr($lang,'কোন সেবাটি চালাবেন?','Which service should run?'))."\n".LINE."\n🔢 ".mono($number).' · 📅 '.mono(prettyDate($date)),'markup'=>kb($rows)];
}
function noticeView(string $lang,string $title,string $body,array $extra=[]): array {
    $extra[]=btn(tr($lang,'✖️ বন্ধ','✖️ Close'),'f:close');
    return ['text'=>$title."\n".LINE."\n".$body,'markup'=>kb([$extra])];
}

function cardConcepts(): array {
    return [
        'namebn'=>['📛','নাম','Name (Bangla)'],'nameen'=>['🔤','নাম (ইংরেজি)','Name (English)'],'dob'=>['🎂','জন্ম তারিখ','Date of Birth'],
        'dod'=>['🕯️','মৃত্যু তারিখ','Date of Death'],'gender'=>['⚧️','লিঙ্গ','Gender'],'age'=>['🎈','বয়স','Age'],
        'pobb'=>['📍','জন্মস্থান','Place of Birth'],'pobe'=>['🌐','জন্মস্থান (ইংরেজি)','Place of Birth (English)'],
        'podbn'=>['🕊️','মৃত্যুস্থান','Place of Death'],'poden'=>['🌐','মৃত্যুস্থান (ইংরেজি)','Place of Death (English)'],
        'fatherbn'=>['👨','পিতার নাম',"Father's Name"],'fatheren'=>['🔤','পিতার নাম (ইংরেজি)',"Father's Name (English)"],
        'fathernatbn'=>['🌍','পিতার জাতীয়তা',"Father's Nationality"],'fathernaten'=>['🌍','পিতার জাতীয়তা (EN)',"Father's Nationality (EN)"],
        'motherbn'=>['👩','মাতার নাম',"Mother's Name"],'motheren'=>['🔤','মাতার নাম (ইংরেজি)',"Mother's Name (English)"],
        'mothernatbn'=>['🌍','মাতার জাতীয়তা',"Mother's Nationality"],'mothernaten'=>['🌍','মাতার জাতীয়তা (EN)',"Mother's Nationality (EN)"],
        'regno'=>['🔢','নিবন্ধন নম্বর','Registration No'],'regdate'=>['📅','নিবন্ধন তারিখ','Registration Date'],
        'regoffice'=>['🏢','নিবন্ধন অফিস','Registration Office'],'issuedate'=>['📜','ইস্যু তারিখ','Issuance Date'],
        'village'=>['🏡','গ্রাম','Village'],'postoffice'=>['📮','ডাকঘর','Post Office'],'addr_union'=>['🏘','ইউনিয়ন','Union'],
        'addr_upazila'=>['🏙','উপজেলা','Upazila'],'addr_district'=>['🗺','জেলা','District'],'office'=>['🏤','ইউনিয়ন / অফিস','Union / Office'],
        'officeupazila'=>['🏙','উপজেলা','Upazila'],'officedistrict'=>['🗺','জেলা','District'],'officelocation'=>['🧭','অফিসের ঠিকানা','Office Address'],
    ];
}
function cardAliases(): array {
    static $alias;
    if ($alias!==null) return $alias;
    $alias=[];
    $add=static function(string $id,array $names) use (&$alias): void { foreach($names as $name)$alias[normalizeKey($name)]=$id; };
    $add('namebn',['namebangla','namebn','registeredpersonnamebangla','persondetailsnamebn','personnamebangla']);
    $add('nameen',['nameenglish','nameen','registeredpersonname','persondetailsnameen','personnameenglish','nameeng']);
    $add('dob',['dateofbirth','dob','birthdate','birthdetailsdateofbirth','dateofbirthbangla']);
    $add('dod',['dateofdeath','dod','deathdate','deathdetailsdateofdeath']);
    $add('gender',['gender','sex','genderenglish','genderbangla','birthdetailssex','genderen']);
    $add('age',['age','ageyears','boyosh']);
    $add('pobb',['birthplacebangla','placeofbirthbn','placeofbirthbangla','persondetailsplaceofbirthbn','birthplacebn','placeofbirth']);
    $add('pobe',['birthplaceenglish','placeofbirthen','placeofbirthenglish','persondetailsplaceofbirthen','birthplaceen']);
    $add('podbn',['placeofdeathbn','placeofdeathbangla','placeofdeath']); $add('poden',['placeofdeathen','placeofdeathenglish']);
    $add('fatherbn',['fathernamebangla','fathernamebn','parentsdetailsfathernamebn','fatherbn']);
    $add('fatheren',['fathernameenglish','fathernameen','parentsdetailsfathernameen']);
    $add('fathernatbn',['fathernationalitybangla','fathernationalitybn','parentsdetailsfathernationalitybn']);
    $add('fathernaten',['fathernationalityenglish','fathernationalityen','parentsdetailsfathernationalityen']);
    $add('motherbn',['mothernamebangla','mothernamebn','parentsdetailsmothernamebn','motherbn']);
    $add('motheren',['mothernameenglish','mothernameen','parentsdetailsmothernameen']);
    $add('mothernatbn',['mothernationalitybangla','mothernationalitybn','parentsdetailsmothernationalitybn']);
    $add('mothernaten',['mothernationalityenglish','mothernationalityen','parentsdetailsmothernationalityen']);
    $add('regno',['brn','drn','nid','nidnumber','nationalid','registrationnumber','birthregistrationnumber','deathregistrationnumber','birthdetailsbirthregistrationnumber','birthdetailsregistrationnumber']);
    $add('regdate',['registrationdate','registrationdetailsregistrationdate','regdate']);
    $add('regoffice',['registrationoffice','registrationdetailsregistrationoffice','registeroffice','registerofficeenglish','registerofficename']);
    $add('issuedate',['issuedate','issuancedate','registrationdetailsissuancedate','certificateissuedate']);
    $add('village',['village','addressdetailsplaceofbirthvillage','placeofbirthvillage','addressvillage']);
    $add('postoffice',['postoffice','addressdetailsplaceofbirthpostoffice','placeofbirthpostoffice']);
    $add('addr_union',['union','addressdetailsplaceofbirthunion','placeofbirthunion','addressunion']);
    $add('addr_upazila',['addressdetailsplaceofbirthupazila','placeofbirthupazila','addressupazila','upazila']);
    $add('addr_district',['addressdetailsplaceofbirthdistrict','placeofbirthdistrict','addressdistrict','district']);
    $add('office',['registerofficedetailsunionoroffice','unionoroffice','unionoffice','registrationofficeunion']);
    $add('officeupazila',['registerofficedetailsupazila','registerofficeupazila']);
    $add('officedistrict',['registerofficedetailsdistrict','registerofficedistrict']);
    $add('officelocation',['registerofficedetailslocation','officelocation','registrationofficelocation','location']);
    return $alias;
}
function cardLabelAliases(): array {
    return [
        'নিবন্ধিত ব্যক্তির নাম'=>'namebn','জন্মস্থান'=>'pobb','মাতার নাম'=>'motherbn','মাতার জাতীয়তা'=>'mothernatbn',
        'পিতার নাম'=>'fatherbn','পিতার জাতীয়তা'=>'fathernatbn','registration date'=>'regdate',
        'registration office'=>'regoffice','issuance date'=>'issuedate','date of birth'=>'dob','date of death'=>'dod',
        'birth registration number'=>'regno','death registration number'=>'regno','sex'=>'gender','gender'=>'gender',
        'registered person name'=>'nameen','place of birth'=>'pobe','place of death'=>'poden',
        "mother's name"=>'motheren',"mother's nationality"=>'mothernaten',"father's name"=>'fatheren',
        "father's nationality"=>'fathernaten',
    ];
}
function stripLangKey(string $key): array {
    $key=normalizeKey($key);
    if (preg_match('/^(.*?)(bangla|bengali|bangladeshi)$/',$key,$m) && strlen($m[1])>1) return [$m[1],'bn'];
    if (preg_match('/^(.*?)(english|eng)$/',$key,$m) && strlen($m[1])>1) return [$m[1],'en'];
    if (preg_match('/^(.*?)bn$/',$key,$m) && strlen($m[1])>2) return [$m[1],'bn'];
    if (preg_match('/^(.*?)en$/',$key,$m) && strlen($m[1])>2) return [$m[1],'en'];
    return [$key,''];
}
function hasBengali(mixed $value): bool { return (bool)preg_match('/[\x{0980}-\x{09FF}]/u',(string)($value??'')); }
function cardResolve(array $path,string $extraSuffix,mixed $value): ?string {
    $last=(string)($path[count($path)-1]??'');
    $prev=count($path)>1?(string)$path[count($path)-2]:'';
    $container=count($path)>1?(string)$path[0]:'';
    [$base,$suffix0]=stripLangKey($last); $suffix=$extraSuffix?:$suffix0;
    $candidates=array_filter([
        normalizeKey($container).$base.$suffix,normalizeKey($prev).$base.$suffix,$base.$suffix,
        normalizeKey($container).$base,normalizeKey($prev).$base,$base,
        normalizeKey($container).$base.'bn',normalizeKey($prev).$base.'bn',
    ]);
    $aliases=cardAliases(); $id=null;
    foreach($candidates as $candidate) if(isset($aliases[$candidate])){$id=$aliases[$candidate];break;}
    if($id===null)return null;
    $bnToEn=['namebn'=>'nameen','fatherbn'=>'fatheren','motherbn'=>'motheren','pobb'=>'pobe','podbn'=>'poden','fathernatbn'=>'fathernaten','mothernatbn'=>'mothernaten'];
    $enToBn=['nameen'=>'namebn','fatheren'=>'fatherbn','motheren'=>'motherbn','pobe'=>'pobb','poden'=>'podbn'];
    if(isset($bnToEn[$id])&&!hasBengali($value))$id=$bnToEn[$id];
    elseif(isset($enToBn[$id])&&hasBengali($value))$id=$enToBn[$id];
    return $id;
}
function cardValue(string $id,mixed $value,string $lang): string {
    $raw=trim((string)($value??'')); $raw=(string)preg_replace('/^[:：]\s*/u','',$raw);
    if($id==='gender')return prettyValue('gender',$raw,$lang);
    if(in_array($id,['dob','dod','regdate','issuedate'],true))return prettyValue('date',$raw,$lang);
    return prettyValue($id,$raw,$lang);
}
function cardSections(mixed $data,string $lang): array {
    $hits=[]; $extras=[]; $seen=[]; $aliases=cardAliases(); $labelAliases=cardLabelAliases();
    $put=static function(string $id,mixed $value) use (&$hits,&$seen): void {
        $v=trim((string)($value??'')); if($v==='')return;
        $sig=$id.'|'.strtolower((string)preg_replace('/\s+/u',' ',$v));
        if(isset($seen[$sig]))return; $seen[$sig]=true; $hits[$id][]=$v;
    };
    $extra=static function(string $label,mixed $value) use (&$extras,&$seen): void {
        $v=trim((string)($value??'')); if($v==='')return;
        $sig=normalizeKey($label).'|'.strtolower($v); if(isset($seen[$sig]))return;
        $seen[$sig]=true; $extras[]=[$label,$v];
    };
    $extraLabel=static function(array $path,string $key,string $lang): string {
        $base=labelOf($key,$lang);
        if(count($path)>1){$group=labelOf((string)$path[0],$lang);return normalizeKey((string)$path[0])===normalizeKey($key)?$base:$group.' · '.$base;}
        return $base;
    };
    $walk=function(mixed $obj,array $path,int $depth) use (&$walk,$put,$extra,$extraLabel,$aliases,$labelAliases,$lang): void {
        if(!is_array($obj)||$depth>5)return;
        if(array_is_list($obj)){
            if(isset($obj[0])&&is_array($obj[0])&&array_key_exists('label',$obj[0])){
                foreach($obj as $field){
                    if(!is_array($field)||!isset($field['label'])||!array_key_exists('value',$field)||trim((string)$field['value'])==='')continue;
                    $label=(string)$field['label']; $id=$aliases[normalizeKey($label)]??$labelAliases[strtolower(trim($label))]??null;
                    $id?$put($id,$field['value']):$extra(labelOf($label,$lang),$field['value']);
                }
                return;
            }
            foreach($obj as $item)$walk($item,$path,$depth+1);
            return;
        }
        foreach($obj as $key=>$value){
            $key=(string)$key; if(in_array(normalizeKey($key),SKIP_KEYS,true)||$value===null||$value==='')continue;
            $next=array_merge($path,[$key]);
            if(is_array($value)){
                if(array_is_list($value)){$walk($value,$next,$depth+1);continue;}
                $keys=array_keys($value);
                if(count($keys)===1&&in_array((string)$keys[0],['bn','en'],true)&&!is_array($value[$keys[0]])){
                    $raw=$value[$keys[0]];$id=cardResolve($next,(string)$keys[0],$raw);
                    $id?$put($id,$raw):$extra($extraLabel($path,$key,$lang),$raw);continue;
                }
                if(array_key_exists('bn',$value)||array_key_exists('en',$value)){
                    foreach(['bn','en'] as $suffix){
                        if(!array_key_exists($suffix,$value)||is_array($value[$suffix])||trim((string)$value[$suffix])==='')continue;
                        $id=cardResolve($next,$suffix,$value[$suffix]);
                        $id?$put($id,$value[$suffix]):$extra($extraLabel($path,$key,$lang).($suffix==='en'?' (EN)':''),$value[$suffix]);
                    }
                    foreach($value as $childKey=>$child)if(!in_array((string)$childKey,['bn','en'],true))$walk($child,array_merge($next,[(string)$childKey]),$depth+1);
                    continue;
                }
                $walk($value,$next,$depth+1);continue;
            }
            $id=cardResolve($next,'',$value);
            $id?$put($id,$value):$extra($extraLabel($path,$key,$lang),$value);
        }
    };
    $walk($data,[],0);
    $concepts=cardConcepts(); $sections=[];
    $lineOf=static function(string $id) use (&$hits,$concepts,$lang): ?string {
        if(empty($hits[$id]))return null; $values=$hits[$id];unset($hits[$id]);$def=$concepts[$id];
        return bold($def[0].' '.tr($lang,$def[1],$def[2])).': '.implode(' · ',array_map(static fn($v)=>cardValue($id,$v,$lang),$values));
    };
    $pairLine=static function(array $ids,string $bn,string $en,string $emoji) use (&$hits,$lang): ?string {
        $values=[];foreach($ids as $id){foreach($hits[$id]??[] as $v)$values[]=cardValue($id,$v,$lang);unset($hits[$id]);}
        $values=array_values(array_unique($values));return $values?bold($emoji.' '.tr($lang,$bn,$en)).': '.implode(' · ',$values):null;
    };
    $chain=static function(array $ids) use (&$hits,$concepts,$lang): array {
        $items=[];foreach($ids as $id){if(empty($hits[$id]))continue;$def=$concepts[$id];$items[]=[$def[0],tr($lang,$def[1],$def[2]),implode(' · ',array_map(static fn($v)=>cardValue($id,$v,$lang),$hits[$id]))];unset($hits[$id]);}
        $out=[];foreach($items as $i=>$it)$out[]=($i===0?bold($it[0].' '.$it[1]).': ':'　'.str_repeat('　',$i-1).'👉 '.bold($it[1]).': ').$it[2];return $out;
    };
    $place=static function(string $id) use (&$hits,$concepts,$lang): ?string {
        if(empty($hits[$id]))return null;$def=$concepts[$id];$raw=implode(' · ',$hits[$id]);unset($hits[$id]);$parts=preg_split('/\s*,\s*/u',$raw,-1,PREG_SPLIT_NO_EMPTY);
        if(count($parts)>=3&&!array_filter($parts,static fn($x)=>str_contains($x,':')))return bold($def[0].' '.tr($lang,$def[1],$def[2])).":\n　▪️ ".e($parts[0]).implode('',array_map(static fn($x)=>"\n　👉 ".e($x),array_slice($parts,1)));
        return bold($def[0].' '.tr($lang,$def[1],$def[2])).': '.cardValue($id,$raw,$lang);
    };
    $add=static function(string $title,array $lines) use (&$sections): void {$lines=array_values(array_filter($lines,static fn($v)=>$v!==null&&$v!==''));if($lines)$sections[]=['title'=>$title,'lines'=>$lines];};
    $add('👤 '.tr($lang,'ব্যক্তির তথ্য','Person Details'),array_merge(array_map($lineOf,['namebn','nameen','dob','dod','gender','age']),[$lineOf('pobb'),$lineOf('pobe'),$place('podbn'),$place('poden')]));
    $add('👨‍👩‍👦 '.tr($lang,'পিতামাতার তথ্য','Parents'),[$lineOf('fatherbn'),$lineOf('fatheren'),$pairLine(['fathernatbn','fathernaten'],'পিতার জাতীয়তা',"Father's Nationality",'🌍'),$lineOf('motherbn'),$lineOf('motheren'),$pairLine(['mothernatbn','mothernaten'],'মাতার জাতীয়তা',"Mother's Nationality",'🌍')]);
    $add('🏛 '.tr($lang,'নিবন্ধন তথ্য','Registration'),array_map($lineOf,['regno','regdate','regoffice','issuedate']));
    $office=[];$officeChain=$chain(['officedistrict','officeupazila','office']);$placeChain=$chain(['addr_district','addr_upazila','addr_union','village','postoffice']);
    if($officeChain)$office=array_merge($office,[ital(tr($lang,'🏛 নিবন্ধন অফিসের এলাকা','🏛 Registration Office Area'))],$officeChain);
    if($location=$lineOf('officelocation'))$office[]=$location;
    if($placeChain)$office=array_merge($office,[ital(tr($lang,'📍 জন্মস্থানের এলাকা','📍 Birthplace Area'))],$placeChain);
    $add('🗺 '.tr($lang,'অফিস ও ঠিকানা','Office & Address'),$office);
    if($hits)$add('📋 '.tr($lang,'অতিরিক্ত তথ্য','Additional Information'),array_map($lineOf,array_keys($hits)));
    if($extras)$add('🗒 '.tr($lang,'অন্যান্য তথ্য','Other Information'),array_map(static fn($x)=>bold($x[0]).': '.prettyValue('x',$x[1],$lang),$extras));
    return $sections;
}

function resultView(string $lang,array $button,array $json,string $number,?string $date): array {
    $title=($button['emoji']?:'🔎').' '.bold($lang==='en'?$button['title_en']:$button['title_bn']);
    $query="🔢 ".mono($number).($date?' · 📅 '.mono(prettyDate($date)):'');
    if(!apiOk($json)){
        $reason=(string)($json['message']??$json['error']??tr($lang,'রেকর্ড পাওয়া যায়নি','No record found'));
        $lines=[$title,LINE,'❌ '.bold(tr($lang,'কোনো ফলাফল পাওয়া যায়নি','No result found')),ital($reason),'','• '.tr($lang,'নম্বরটি হুবহু সঠিক কি না দেখুন','Make sure the number is correct')];
        if((int)$button['needs_date'])$lines[]='• '.tr($lang,'তারিখ সনদের সাথে মিলছে কি না দেখুন','Check the date matches the certificate');
        $lines[]='';$lines[]=$query;
        return ['text'=>implode("\n",$lines),'markup'=>kb([[btn(tr($lang,'🔁 আবার চেষ্টা','🔁 Try again'),'svc:'.$button['id']),btn(tr($lang,'🏠 হোম','🏠 Home'),'panel')]])];
    }
    $data=is_array($json['data']??null)?$json['data']:(is_array($json['result']??null)?$json['result']:$json);
    $source=(string)($data['record_source']??$data['recordSource']??tr($lang,'নিবন্ধন ডাটাবেস','Registration database'));
    $head=$title."\n".LINE."\n✅ ".bold(tr($lang,'যাচাই সফল হয়েছে','Verification Successful'));
    $foot=LINE."\n🗂 ".tr($lang,'তথ্যসূত্র','Source').': '.ital($source)."\n".$query;
    $sections=cardSections($data,$lang);$total=count($sections);
    $build=static function(array $secs) use ($head,$foot): string {
        $body=implode("\n\n",array_map(static fn($s)=>bold($s['title'])."\n".implode("\n",$s['lines']),$secs));
        return trim((string)preg_replace('/\n{3,}/',"\n\n",implode("\n\n",array_filter([$head,$body,$foot]))));
    };
    $text=$build($sections);
    while(mb_strlen($text)>3800&&count($sections)>2){array_pop($sections);$text=$build($sections);}
    if(mb_strlen($text)>3800)$text=mb_substr($text,0,3790).'…';
    if(count($sections)<$total)$text.="\n\n".ital(tr($lang,'📋 আরও '.localNum($lang,$total-count($sections)).' টি সেকশন আছে','📋 '.($total-count($sections)).' more section(s) available'));
    return ['text'=>$text,'markup'=>kb([[btn(tr($lang,'🔁 নতুন যাচাই','🔁 New check'),'svc:'.$button['id']),btn(tr($lang,'🧾 হিস্টোরি','🧾 History'),'hist')],[btn(tr($lang,'🏠 হোম','🏠 Home'),'panel')]])];
}

function loadingBar(int $pct): string {$fill=max(0,min(16,(int)round($pct/100*16)));return str_repeat('█',$fill).str_repeat('░',16-$fill);}
function loadStage(string $lang,int $pct): string {
    if($pct<25)return tr($lang,'🔌 সার্ভারে অনুরোধ পাঠানো হয়েছে','🔌 Request sent to server');
    if($pct<65)return tr($lang,'📡 সরকারি ডাটাবেসে খোঁজা হচ্ছে','📡 Searching the official database');
    if($pct<88)return tr($lang,'🧩 রেকর্ড পাওয়া গেছে, সাজানো হচ্ছে','🧩 Record found, preparing it');
    return tr($lang,'🖨 ফলাফল তৈরি হচ্ছে','🖨 Building your result');
}
function loadingView(string $lang,array $button,string $number,?string $date,int $frame,int $pct,int $seconds): array {
    return ['text'=>($button['emoji']?:'🔎').' '.bold($lang==='en'?$button['title_en']:$button['title_bn'])."\n".LINE."\n".SPIN[$frame%count(SPIN)].' '.bold(tr($lang,'যাচাই করা হচ্ছে…','Verifying…'))."\n\n".e(loadStage($lang,$pct))."\n".mono(loadingBar($pct)).' '.bold($pct.'%')."\n\n⏱️ ".tr($lang,'সময়','Elapsed').': '.localNum($lang,$seconds).' '.tr($lang,'সেকেন্ড','sec')."\n🔢 ".mono($number).($date?' · 📅 '.mono(prettyDate($date)):'')."\n\n".ital(tr($lang,'অনুগ্রহ করে অপেক্ষা করুন, তথ্য আনা হচ্ছে…','Please wait, fetching the record…')),'markup'=>kb([])];
}
function fetchJsonLive(string $url,string $chat,int $mid,string $lang,array $button,string $number,?string $date): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $mh=curl_multi_init();curl_multi_add_handle($mh,$ch);$running=null;$start=microtime(true);$last=$start;$frame=0;
    do{
        do{$status=curl_multi_exec($mh,$running);}while($status===CURLM_CALL_MULTI_PERFORM);
        $now=microtime(true);
        if($running&&$now-$last>=($frame===0?1.2:2.1)){
            $elapsed=$now-$start;$pct=min(94,(int)round((1-exp(-$elapsed/7))*100));$frame++;
            editOnly($chat,$mid,loadingView($lang,$button,$number,$date,$frame,$pct,(int)round($elapsed))['text'],kb([]));$last=microtime(true);
        }
        if($running)curl_multi_select($mh,0.2);
    }while($running&&$status===CURLM_OK);
    $raw=curl_multi_getcontent($ch);$errno=curl_errno($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh,$ch);curl_multi_close($mh);curl_close($ch);
    if($errno===CURLE_OPERATION_TIMEDOUT)return ['success'=>false,'message'=>'TIMEOUT'];
    if($errno)return ['success'=>false,'message'=>'NETWORK'];
    $json=json_decode((string)$raw,true);return is_array($json)?$json:['success'=>false,'message'=>'HTTP '.$code];
}
function rateLimited(string $uid,int $limit): bool {return (int)(one('SELECT COUNT(*) n FROM checks WHERE user_id=? AND created_at>?',[$uid,nowms()-60000])['n']??0)>=$limit;}
function runCheck(string $uid,string $chat,array &$state,string $lang,array $button,string $number,?string $date): void {
    $limit=max(1,(int)setting('rate','20')?:20);
    $shown=(int)$button['needs_date']?$date:null;
    $mid=present(loadingView($lang,$button,$number,$shown,0,5,0),$chat,$state,'flow',empty($GLOBALS['_tap_mid']));
    if(rateLimited($uid,$limit)){
        $state['step']=null;
        present(noticeView($lang,'⛔ '.bold(tr($lang,'একটু অপেক্ষা করুন','Please wait')),tr($lang,'প্রতি মিনিটে সর্বোচ্চ '.localNum($lang,$limit).'টি যাচাই করা যায়।','Maximum '.$limit.' checks per minute.')),$chat,$state,'flow');return;
    }
    $json=$mid?fetchJsonLive(apiUrl($button['url'],$number,$date),$chat,$mid,$lang,$button,$number,$shown):['success'=>false,'message'=>'NETWORK'];
    if(in_array((string)($json['message']??''),['TIMEOUT','NETWORK'],true)){
        $state['step']=null;$type=$json['message'];
        $body=tr($lang,$type==='TIMEOUT'?'সার্ভার সময়মতো উত্তর দেয়নি। আবার চেষ্টা করুন।':'সার্ভারে সংযোগ হয়নি। আবার চেষ্টা করুন।',$type==='TIMEOUT'?'The server did not respond in time. Try again.':'Could not reach the server. Try again.');
        present(noticeView($lang,'🔌 '.bold(tr($lang,'সার্ভার সমস্যা','Server error')),$body,[btn(tr($lang,'🔁 আবার','🔁 Retry'),'svc:'.$button['id'])]),$chat,$state,'flow');return;
    }
    $ok=apiOk($json);
    q('INSERT INTO checks(user_id,button_id,title,number,date,ok,created_at) VALUES(?,?,?,?,?,?,?)',[$uid,$button['id'],$lang==='en'?$button['title_en']:$button['title_bn'],$number,$date??'',$ok?1:0,nowms()]);
    q('UPDATE users SET checks=checks+1,last_seen=? WHERE id=?',[nowms(),$uid]);
    if(setting('broadcast_log')==='1')logAction($uid,$ok?'check_ok':'check_fail',$button['id'].'|'.$number);
    $state['step']=null;unset($state['data']['number']);
    present(resultView($lang,$button,$json,$number,$shown),$chat,$state,'flow');
}

function userAdminView(string $lang, int $offset=0): array {
    $offset=max(0,$offset); $limit=8;
    $rows=all('SELECT id,name,username,blocked,checks,last_seen,created_at FROM users ORDER BY created_at DESC LIMIT '.$limit.' OFFSET '.$offset);
    $total=(int)(one('SELECT COUNT(*) n FROM users')['n']??0);
    $text="👥 ".bold(tr($lang,'ইউজার ম্যানেজমেন্ট','USER MANAGEMENT'))."\n".DLINE."\n";
    if(!$rows) $text.=ital(tr($lang,'কোনো ইউজার পাওয়া যায়নি।','No users found.'))."\n";
    $buttons=[];
    foreach($rows as $u){
        $display=trim((string)$u['name']) ?: (string)$u['id'];
        $text.="👤 ".bold(trunc($display,30)).(!empty($u['username'])?' · @'.e($u['username']):'')."\n🆔 ".mono($u['id'])." · ".((int)$u['blocked']?'🚫 '.tr($lang,'ব্যান','Blocked'):'🟢 '.tr($lang,'চালু','Active'))." · ".tr($lang,'চেক','Checks').': '.bnNum($u['checks'])."\n\n";
        $buttons[]=[btn('👤 '.trunc($display,18),'usr:view:'.$u['id'])];
    }
    $nav=[];
    if($offset>0)$nav[]=btn('◀️', 'usr:list:'.max(0,$offset-$limit));
    if($offset+$limit<$total)$nav[]=btn('▶️', 'usr:list:'.($offset+$limit));
    if($nav)$buttons[]=$nav;
    $buttons[]=[btn(tr($lang,'🔙 অ্যাডমিন প্যানেল','🔙 Admin Panel'),'adm')];
    return ['text'=>$text.ital(tr($lang,'মোট '.bnNum($total).' জন ইউজার','Total '.$total.' users')), 'markup'=>kb($buttons)];
}
function userDetailView(string $lang,array $u): array {
    $name=trim((string)$u['name']) ?: (string)$u['id'];
    $status=(int)$u['blocked'] ? '🚫 '.tr($lang,'ব্যান করা','Blocked') : '🟢 '.tr($lang,'চালু','Active');
    $text="👤 ".bold(trunc($name,50))."\n".LINE."\n🆔 ".mono($u['id'])."\n";
    if(!empty($u['username']))$text.='🔗 @'.e($u['username'])."\n";
    $text.="📅 ".e(dayShort((int)$u['created_at']))."\n👁 ".e(dayShort((int)$u['last_seen']))."\n🧾 ".tr($lang,'চেক','Checks').': '.bnNum($u['checks'])."\n📌 ".$status;
    $action=(int)$u['blocked'] ? btn('🟢 '.tr($lang,'আনব্যান','Unblock'),'usr:unblock:'.$u['id']) : btn('🚫 '.tr($lang,'ব্যান','Ban'),'usr:block:'.$u['id']);
    return ['text'=>$text,'markup'=>kb([[$action],[btn(tr($lang,'🔙 ইউজার তালিকা','🔙 User List'),'usr:list:0')],[btn(tr($lang,'🏠 হোম','🏠 Home'),'panel')]])];
}
function setUserBlocked(string $target,string $admin,string $chat,bool $blocked): bool {
    if($target===$admin || isAdmin($target)) return false;
    $exists=one('SELECT id FROM users WHERE id=?',[$target]); if(!$exists)return false;
    q('UPDATE users SET blocked=? WHERE id=?',[$blocked?1:0,$target]);
    logAction($admin,$blocked?'user_block':'user_unblock',$target);
    sendMsg($target,$blocked?'🚫 আপনার অ্যাকাউন্ট অ্যাডমিন দ্বারা সাময়িকভাবে বন্ধ করা হয়েছে।':'🟢 আপনার অ্যাকাউন্ট আবার চালু করা হয়েছে।');
    return true;
}
function adminView(string $lang): array {
    return ['text'=>"🛠 ".bold(tr($lang,'অ্যাডমিন প্যানেল','ADMIN PANEL'))."\n".DLINE."\n⚙️ ".bold(tr($lang,'কনফিগারেশন সেটিংস','CONFIGURATION SETTINGS'))."\n\n🏷 ".tr($lang,'বট নাম','Bot name').': '.bold(setting('brand'))."\n💬 ".tr($lang,'সাপোর্ট','Support').': '.bold(setting('support')?:'—')."\n🔔 ".tr($lang,'নতুন ইউজার নোটিফিকেশন','New-user notification').': '.(setting('notify_new')==='1'?'✅':'❌')."\n📊 ".tr($lang,'রেট লিমিট','Rate limit').': '.bold(localNum($lang,setting('rate','20')).'/'.tr($lang,'মিনিট','min'))."\n🚧 ".tr($lang,'মেইনটেন্যান্স','Maintenance').': '.(setting('maint')==='1'?'✅':'❌'),'markup'=>kb([[btn('👥 '.tr($lang,'ইউজার ম্যানেজমেন্ট','User Management'),'usr:list:0')],[btn('⚙️ '.tr($lang,'বট সেটিংস','Bot Settings'),'as:general')],[btn('🔗 '.tr($lang,'API সেটিংস','API Settings'),'as:api')],[btn(tr($lang,'🔙 ব্যাক','🔙 Back'),'panel'),btn(tr($lang,'✖️ বন্ধ','✖️ Close'),'adm:close')]])];
}
function settingsView(string $lang,string $section='general'): array {
    $rows=[];$text='⚙️ '.bold(tr($lang,$section==='api'?'API সেটিংস':'জেনারেল সেটিংস',$section==='api'?'API SETTINGS':'GENERAL SETTINGS'))."\n".LINE."\n";
    if($section==='api'){
        foreach(buttons(false) as $b){$rows[]=[btn(($b['emoji']?:'🔎').' '.trunc($lang==='en'?$b['title_en']:$b['title_bn'],25),'aset:b:'.$b['id'])];$text.=($b['emoji']?:'🔎').' '.e($lang==='en'?$b['title_en']:$b['title_bn'])."\n　🔗 ".mono(trunc($b['url'],60))."\n";}
    }else{
        $text.="🏷 ".tr($lang,'বট নাম','Bot name').': '.bold(setting('brand'))."\n💬 ".tr($lang,'সাপোর্ট','Support').': '.bold(setting('support')?:'—')."\n📝 ".tr($lang,'স্বাগত বার্তা','Welcome').': '.ital(trunc(setting($lang==='en'?'welcome_en':'welcome_bn')?:'—',42))."\n🔔 ".tr($lang,'নতুন ইউজার নোটিফিকেশন','New-user notification').': '.(setting('notify_new')==='1'?'✅':'❌')."\n📊 ".tr($lang,'রেট লিমিট','Rate limit').': '.bold(localNum($lang,setting('rate','20')).'/'.tr($lang,'মিনিট','min'))."\n🚧 ".tr($lang,'মেইনটেন্যান্স','Maintenance').': '.(setting('maint')==='1'?'✅':'❌')."\n";
        $rows=[[btn('🏷 '.tr($lang,'বট নাম','Bot name'),'aset:s:brand'),btn('💬 '.tr($lang,'সাপোর্ট','Support'),'aset:s:support')],[btn('📝 '.tr($lang,'স্বাগত বার্তা','Welcome'),'aset:s:welcome_bn'),btn('📊 '.tr($lang,'রেট লিমিট','Rate'),'aset:s:rate')],[btn('🔔 '.tr($lang,'নোটিফিকেশন চালু/বন্ধ','Toggle notifications'),'aset:notify'),btn('🚧 '.tr($lang,'মেইনটেন্যান্স চালু/বন্ধ','Toggle maintenance'),'aset:toggle')],[btn('🚧 '.tr($lang,'মেইনটেন্যান্স বার্তা','Maintenance message'),'aset:s:maint_msg_bn'),btn('♻️ '.tr($lang,'ডিফল্ট রিসেট','Reset defaults'),'aset:reset')]];
    }
    $rows[]=[btn(tr($lang,'🔙 প্যানেল','Panel'),'adm'),btn(tr($lang,'✖️ বন্ধ','Close'),'adm:close')];
    return ['text'=>$text,'markup'=>kb($rows)];
}
function promptView(string $lang,string $title,string $body,string $back='adm'): array {
    return ['text'=>bold($title)."\n".LINE."\n$body\n\n".ital(tr($lang,'নিচে লিখে পাঠান 👇','Type below and send 👇')),'markup'=>kb([[btn(tr($lang,'🔙 পেছনে','🔙 Back'),$back),btn(tr($lang,'❌ বাতিল','❌ Cancel'),'adm')]])];
}

function looseNumberDate(string $text): ?array {
    $text=digitize(trim($text));
    if(!preg_match('/^(\d{10,20})\D+(.+)$/u',$text,$m))return null;
    $date=parseDate(trim($m[2]));return $date?[$m[1],$date]:null;
}
function startFlow(string $chat,array &$state,string $lang,array $button,bool $fresh=false): void {
    $state['step']='number';$state['data']['bid']=$button['id'];unset($state['data']['number']);present(flowAsk($lang,$button),$chat,$state,'flow',$fresh);
}
function handleText(string $uid,string $chat,array &$state,string $lang,string $text,bool $admin): void {
    $step=$state['step']??null;
    if($step==='number'){
        $button=getButton((int)($state['data']['bid']??0));
        if(!$button||!(int)$button['active']){$state['step']=null;present(panelView($lang,one('SELECT name FROM users WHERE id=?',[$uid])['name']??'User',$admin),$chat,$state,'panel');return;}
        if($combo=looseNumberDate($text)){
            if((int)$button['needs_date'])runCheck($uid,$chat,$state,$lang,$button,$combo[0],$combo[1]);else runCheck($uid,$chat,$state,$lang,$button,$combo[0],null);return;
        }
        $digits=(string)preg_replace('/\D/','',digitize($text));
        if(strlen($digits)!==17){present(['text'=>'⚠️ '.bold(tr($lang,'ভুল নম্বর ফরম্যাট','Invalid number format'))."\n".LINE."\n".tr($lang,'নম্বরটি অবশ্যই ১৭ ডিজিট হতে হবে।','The number must be exactly 17 digits.')."\n".tr($lang,'আপনি পাঠিয়েছেন','You sent').': '.mono(trunc($text,24)).' ('.localNum($lang,strlen($digits)).' '.tr($lang,'ডিজিট','digits').')','markup'=>kb([[btn(tr($lang,'❌ বাতিল','❌ Cancel'),'f:cancel')]])],$chat,$state,'flow');return;}
        if(!(int)$button['needs_date']){runCheck($uid,$chat,$state,$lang,$button,$digits,null);return;}
        $state['data']['number']=$digits;$state['step']='date';present(dateAsk($lang,$button,$digits),$chat,$state,'flow',true);return;
    }
    if($step==='date'){
        $button=getButton((int)($state['data']['bid']??0));
        if(!$button||!(int)$button['active']){$state['step']=null;present(panelView($lang,'User',$admin),$chat,$state,'panel');return;}
        $date=parseDate($text);
        if(!$date){present(['text'=>'⚠️ '.bold(tr($lang,'ভুল তারিখ ফরম্যাট','Invalid date format'))."\n".LINE."\n".tr($lang,'তারিখটি বোঝা যায়নি। আবার পাঠান।','Could not read the date. Send again.')."\n".ital(tr($lang,'ফরম্যাট','Format').': 2018-10-12'),'markup'=>kb([[btn(tr($lang,'🔙 পেছনে','🔙 Back'),'f:back'),btn(tr($lang,'❌ বাতিল','❌ Cancel'),'f:cancel')]])],$chat,$state,'flow');return;}
        runCheck($uid,$chat,$state,$lang,$button,(string)($state['data']['number']??''),$date);return;
    }
    if($admin&&str_starts_with((string)$step,'set:')){
        $key=substr((string)$step,4);$value=trim($text)==='-'?'':trunc(trim($text),500);
        if($key==='rate'){$n=(int)preg_replace('/\D/','',digitize($text));if($n<1||$n>500){present(promptView($lang,'📊 RATE LIMIT',tr($lang,'১ থেকে ৫০০ এর মধ্যে একটি সংখ্যা দিন।','Send a number between 1 and 500.'),'as:general'),$chat,$state,'adm');return;}$value=(string)$n;}
        setSetting($key,$value);logAction($uid,'setting',$key);$state['step']=null;present(settingsView($lang),$chat,$state,'adm');return;
    }
    if($admin&&$step==='api_url'){
        $id=(int)($state['data']['bid']??0);$url=trim($text);
        if(!preg_match('~^https?://\S+~i',$url)||!preg_match('/\{(number|brn|drn|nid|n)\}/i',$url)){present(promptView($lang,'🔗 API URL',tr($lang,'লিংক ঠিক নয়। https:// ও {number} থাকতে হবে।','Invalid URL. Must include https:// and {number}.'),'as:api'),$chat,$state,'adm');return;}
        q('UPDATE buttons SET url=?,needs_date=? WHERE id=?',[$url,preg_match('/\{(date|dob|dod|d)\}/i',$url)?1:0,$id]);logAction($uid,'api_setting',(string)$id);$state['step']=null;present(settingsView($lang,'api'),$chat,$state,'adm');return;
    }
    if($combo=looseNumberDate($text)){$services=buttons();$state['step']=null;present(pickServiceView($lang,$combo[0],$combo[1],$services),$chat,$state,'flow',true);return;}
    $state['step']=null;present(panelView($lang,one('SELECT name FROM users WHERE id=?',[$uid])['name']??'User',$admin),$chat,$state,'panel');
}

function callback(string $uid,string $chat,array &$state,string &$lang,array $cb,bool $admin): void {
    $data=(string)($cb['data']??'');$toast=null;
    if($data==='panel'){$state['step']=null;present(panelView($lang,one('SELECT name FROM users WHERE id=?',[$uid])['name']??'User',$admin),$chat,$state,'panel');}
    elseif($data==='close'||$data==='adm:close'){$state['step']=null;present(closeView($lang,$data==='close'?tr($lang,'✖️ বন্ধ করা হয়েছে','✖️ Closed'):tr($lang,'✖️ এডমিন প্যানেল বন্ধ করা হয়েছে','✖️ Admin panel closed')),$chat,$state,$data==='close'?'aux':'adm');}
    elseif($data==='hist')present(historyView($lang,$uid),$chat,$state,'aux');
    elseif($data==='help')present(helpView($lang),$chat,$state,'aux');
    elseif($data==='support')present(supportView($lang),$chat,$state,'aux');
    elseif($data==='lang'){$state['data']['langOrigin']='panel';present(langView($lang),$chat,$state,'panel');}
    elseif($data==='lang:back'){unset($state['data']['langOrigin']);present(panelView($lang,one('SELECT name FROM users WHERE id=?',[$uid])['name']??'User',$admin),$chat,$state,'panel');}
    elseif($data==='lang:bn'||$data==='lang:en'){$lang=$data==='lang:en'?'en':'bn';q('UPDATE users SET lang=? WHERE id=?',[$lang,$uid]);unset($state['data']['langOrigin']);$toast=tr($lang,'✅ ভাষা পরিবর্তন হয়েছে','✅ Language changed');present(panelView($lang,one('SELECT name FROM users WHERE id=?',[$uid])['name']??'User',$admin),$chat,$state,'panel');}
    elseif(str_starts_with($data,'svc:')){$button=getButton(substr($data,4));if(!$button||!(int)$button['active'])$toast=tr($lang,'সেবাটি এখন বন্ধ আছে','This service is inactive');else startFlow($chat,$state,$lang,$button);}
    elseif($data==='f:cancel'||$data==='f:close'){$state['step']=null;unset($state['data']['bid'],$state['data']['number']);present(closeView($lang,tr($lang,'✖️ যাচাই বাতিল করা হয়েছে','✖️ Verification cancelled')),$chat,$state,'flow');}
    elseif($data==='f:back'){$button=getButton((int)($state['data']['bid']??0));if(!$button||!(int)$button['active']){$state['step']=null;present(panelView($lang,'User',$admin),$chat,$state,'panel');}else startFlow($chat,$state,$lang,$button);}
    elseif(str_starts_with($data,'run:')){$parts=explode(':',$data,4);$button=getButton($parts[1]??0);$date=parseDate($parts[3]??'');if(!$button||!(int)$button['active'])$toast=tr($lang,'সেবাটি এখন বন্ধ আছে','This service is inactive');elseif(!preg_match('/^\d{10,20}$/',(string)($parts[2]??''))||((int)$button['needs_date']&&!$date))$toast=tr($lang,'তথ্যটি সঠিক নয়','Invalid request data');else runCheck($uid,$chat,$state,$lang,$button,(string)$parts[2],(int)$button['needs_date']?$date:null);}
    elseif(str_starts_with($data,'usr:list:')&&$admin){$state['step']=null;present(userAdminView($lang,(int)substr($data,9)),$chat,$state,'adm');}
    elseif(str_starts_with($data,'usr:view:')&&$admin){$target=substr($data,9);$u=one('SELECT * FROM users WHERE id=?',[$target]);if($u)present(userDetailView($lang,$u),$chat,$state,'adm');else $toast=tr($lang,'ইউজার পাওয়া যায়নি','User not found');}
    elseif(str_starts_with($data,'usr:block:')&&$admin){$target=substr($data,10);$ok=setUserBlocked($target,$uid,$chat,true);$toast=$ok?tr($lang,'🚫 ইউজার ব্যান করা হয়েছে','🚫 User blocked'):tr($lang,'⚠️ ইউজার পাওয়া যায়নি বা ব্যান করা যাবে না','⚠️ User not found or protected');$u=one('SELECT * FROM users WHERE id=?',[$target]);if($u)present(userDetailView($lang,$u),$chat,$state,'adm');}
    elseif(str_starts_with($data,'usr:unblock:')&&$admin){$target=substr($data,12);$ok=setUserBlocked($target,$uid,$chat,false);$toast=$ok?tr($lang,'🟢 ইউজার আনব্যান করা হয়েছে','🟢 User unblocked'):tr($lang,'⚠️ ইউজার পাওয়া যায়নি বা আনব্যান করা যাবে না','⚠️ User not found or protected');$u=one('SELECT * FROM users WHERE id=?',[$target]);if($u)present(userDetailView($lang,$u),$chat,$state,'adm');}
    elseif($data==='adm'&&$admin){$state['step']=null;present(adminView($lang),$chat,$state,'adm');}
    elseif(str_starts_with($data,'as:')&&$admin){$state['step']=null;present(settingsView($lang,substr($data,3)==='api'?'api':'general'),$chat,$state,'adm');}
    elseif(str_starts_with($data,'aset:s:')&&$admin){$key=substr($data,7);$allowed=['brand','support','welcome_bn','rate','maint_msg_bn'];if(in_array($key,$allowed,true)){$state['step']='set:'.$key;present(promptView($lang,$key==='brand'?'🏷 BRAND':strtoupper($key),$key==='rate'?tr($lang,'প্রতি মিনিটে সর্বোচ্চ যাচাই সংখ্যা (১-৫০০)?','Max checks per minute (1-500)?'):tr($lang,'নতুন মান লিখুন। মুছতে - পাঠান।','Send the new value. Send - to clear.'),'as:general'),$chat,$state,'adm');}}
    elseif($data==='aset:notify'&&$admin){$on=setting('notify_new')!=='1';setSetting('notify_new',$on?'1':'0');logAction($uid,'setting','notify_new='.($on?'1':'0'));$toast=$on?tr($lang,'✅ নতুন ইউজার নোটিফিকেশন চালু হয়েছে','✅ Join notification turned ON'):tr($lang,'✅ নতুন ইউজার নোটিফিকেশন বন্ধ হয়েছে','✅ Join notification turned OFF');present(settingsView($lang),$chat,$state,'adm');}
    elseif($data==='aset:toggle'&&$admin){$on=setting('maint')!=='1';setSetting('maint',$on?'1':'0');logAction($uid,'setting','maint='.($on?'1':'0'));present(settingsView($lang),$chat,$state,'adm');}
    elseif($data==='aset:reset'&&$admin){foreach(DEFAULT_SETTINGS as $key=>$value)setSetting($key,$value);logAction($uid,'setting','reset_defaults');present(settingsView($lang),$chat,$state,'adm');}
    elseif(str_starts_with($data,'aset:b:')&&$admin){$button=getButton((int)substr($data,7));if($button){$state['step']='api_url';$state['data']['bid']=$button['id'];present(promptView($lang,'🔗 API URL',tr($lang,'নতুন API URL পাঠান। {number} ও {date} রাখুন।','Send new API URL with {number} and {date}.'),'as:api'),$chat,$state,'adm');}}
    elseif((str_starts_with($data,'adm')||str_starts_with($data,'as:')||str_starts_with($data,'aset:'))&&!$admin)$toast=tr($lang,'⛔ অনুমতি নেই','⛔ Not allowed');
    answer((string)($cb['id']??''),$toast);
}

function processUpdate(array $update): void {
    $cb=$update['callback_query']??null;$msg=$update['message']??null;$from=$cb['from']??$msg['from']??null;
    if(!is_array($from)||!empty($from['is_bot']))return;
    $uid=(string)$from['id'];$GLOBALS['_uid']=$uid;[$user,$state]=loadState($uid,$from);$lang=($user['lang']??'bn')==='en'?'en':'bn';$chat=(string)($cb['message']['chat']['id']??$msg['chat']['id']??'');if($chat==='')return;
    $admin=isAdminContext($uid,$chat);
    $GLOBALS['_tap_mid']=(int)($cb['message']['message_id']??0);
    if((int)($user['blocked']??0)&&!$admin){present(['text'=>tr($lang,'🚫 আপনার অ্যাকাউন্ট সাময়িক বন্ধ করা হয়েছে। সহায়তার জন্য সাপোর্টে যোগাযোগ করুন।','🚫 Your account has been blocked. Please contact support.'),'markup'=>kb([])],$chat,$state,'panel',empty($state['data']['panel']));return;}
    if(setting('maint')==='1'&&!$admin){present(['text'=>'🚧 '.bold(tr($lang,'রক্ষণাবেক্ষণ চলছে','Under Maintenance'))."\n".LINE."\n".e(setting($lang==='en'?'maint_msg_en':'maint_msg_bn')),'markup'=>kb([])],$chat,$state,'panel',empty($state['data']['panel']));return;}
    if(is_array($cb)){callback($uid,$chat,$state,$lang,$cb,$admin);saveState($uid,$state);return;}
    $text=trim((string)($msg['text']??''));if($text==='')return;
    if(str_starts_with($text,'/')){
        $cmd=strtolower((string)preg_split('/[\s@]/',$text)[0]);$state['step']=null;
        if($cmd==='/start'||$cmd==='/menu'){clearAllTargets($state);present(panelView($lang,$user['name']??'User',$admin),$chat,$state,'panel',true);}
        elseif($cmd==='/history')present(historyView($lang,$uid),$chat,$state,'aux');
        elseif($cmd==='/help')present(helpView($lang),$chat,$state,'aux');
        elseif($cmd==='/support')present(supportView($lang),$chat,$state,'aux');
        elseif($cmd==='/lang'){$state['data']['langOrigin']='panel';present(langView($lang),$chat,$state,'panel');}
        elseif($cmd==='/id')present(idView($lang,$uid),$chat,$state,'aux');
        elseif($cmd==='/close')present(closeView($lang,tr($lang,'✖️ বন্ধ করা হয়েছে','✖️ Closed')),$chat,$state,'aux');
        elseif($cmd==='/admin'&&$admin)present(adminView($lang),$chat,$state,'adm');
        elseif($admin&&($cmd==='/ban'||$cmd==='/unban')){
            $target=trim((string)($textParts=preg_split('/\s+/',trim($text),2)[1]??''));
            if(!preg_match('/^\d{5,20}$/',$target)){sendMsg($chat,tr($lang,'ব্যবহার: /ban USER_ID অথবা /unban USER_ID','Usage: /ban USER_ID or /unban USER_ID'));}
            else { $ok=setUserBlocked($target,$uid,$chat,$cmd==='/ban'); sendMsg($chat,$ok?($cmd==='/ban'?'🚫 User blocked: ':'🟢 User unblocked: ').$target:'⚠️ User not found or protected.'); }
        }
        else present(panelView($lang,$user['name']??'User',$admin),$chat,$state,'panel');
    }else handleText($uid,$chat,$state,$lang,$text,$admin);
    saveState($uid,$state);
}

function webhookSecret(string $token): string {return substr(hash('sha256','tg-hook:'.$token),0,40);}
function setCommands(): array {
    return tg('setMyCommands',['commands'=>[['command'=>'start','description'=>'🏠 হোম — শুরু করুন'],['command'=>'history','description'=>'🧾 হিস্টোরি'],['command'=>'lang','description'=>'🌐 ভাষা পরিবর্তন'],['command'=>'help','description'=>'ℹ️ সহায়তা'],['command'=>'id','description'=>'🆔 আপনার টেলিগ্রাম আইডি'],['command'=>'support','description'=>'💬 সাপোর্ট'],['command'=>'close','description'=>'✖️ বর্তমান ভিউ বন্ধ করুন']], 'scope'=>['type'=>'all_private_chats']]);
}
function setupWebhook(?string $url=null): array {
    $c=cfg();$hook=$url?:baseUrl();$r=tg('setWebhook',['url'=>$hook,'secret_token'=>webhookSecret((string)$c['token']),'allowed_updates'=>['message','callback_query'],'drop_pending_updates'=>false,'max_connections'=>40]);setCommands();schema();
    return ['ok'=>!empty($r['ok']),'webhook'=>$hook,'version'=>VERSION,'result'=>$r['result']??null,'description'=>$r['description']??null];
}
function requestPath(): string {return strtolower((string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/'));}
function routeName(): string {
    $path=rtrim(requestPath(),'/');$script=rtrim(strtolower((string)($_SERVER['SCRIPT_NAME']??'')),'/');
    if($script!==''&&str_starts_with($path,$script))$path=substr($path,strlen($script));
    return trim($path,'/');
}
function jsonResponse(array $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);exit;}
function installer(): void {
    $file=__DIR__.'/.bot-config.php';$error='';
    if($_SERVER['REQUEST_METHOD']==='POST'&&!str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')){
        try{
            $config=['token'=>trim((string)($_POST['token']??'')),'admin_ids'=>array_values(array_filter(preg_split('/[\s,;]+/',trim((string)($_POST['admin_id']??''))))),'admin_chat_id'=>trim((string)($_POST['admin_id']??'')),'host'=>trim((string)($_POST['host']??'localhost')),'db'=>trim((string)($_POST['db']??'')),'user'=>trim((string)($_POST['user']??'')),'pass'=>(string)($_POST['pass']??'')];
            if(!$config['token']||!$config['admin_ids']||!$config['db']||!$config['user'])throw new RuntimeException('Bot token, admin ID, database and username are required.');
            $GLOBALS['_cfg']=$config;schema();$result=setupWebhook(baseUrl());if(empty($result['ok']))throw new RuntimeException('Telegram webhook error: '.($result['description']??'unknown'));
            $config['webhook_secret']=webhookSecret($config['token']);file_put_contents($file,'<?php return '.var_export($config,true).';',LOCK_EX);header('Location: '.baseUrl());exit;
        }catch(Throwable $ex){$error=$ex->getMessage();}
    }
    header('Content-Type:text/html; charset=utf-8');echo '<!doctype html><html lang="bn"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bot Installer</title><style>body{font-family:system-ui;background:#0b1220;color:#eef;display:grid;place-items:center;min-height:100vh}.card{width:min(560px,92vw);padding:28px;background:#152238;border-radius:18px}input{box-sizing:border-box;width:100%;padding:11px;margin:6px 0 13px;border:1px solid #456;border-radius:8px;background:#0d1728;color:#fff}button{padding:12px 18px;border:0;border-radius:9px;background:#36c98f;font-weight:700}label{display:block;color:#b8c8dc}.err{color:#ff9d9d}</style><div class="card"><h1>🤖 Bot Setup</h1><p>PHP 8.x + MySQL · Single file installer</p>'.($error?'<p class="err">'.e($error).'</p>':'').'<form method="post"><label>Telegram Bot Token<input name="token" required></label><label>Owner/Admin Telegram ID<input name="admin_id" required></label><label>MySQL Host<input name="host" value="localhost"></label><label>MySQL Database<input name="db" required></label><label>MySQL Username<input name="user" required></label><label>MySQL Password<input name="pass" type="password"></label><button>🚀 Install &amp; Start</button></form></div></html>';exit;
}

$configFile=__DIR__.'/.bot-config.php';
if(is_file($configFile)) {
    $config=require $configFile; if(!is_array($config)) $config=[];
} else {
    $adminChat=envv('ADMIN_CHAT_ID');
    $adminIds=envv('ADMIN_IDS',$adminChat);
    $config=[
        'token'=>envv('BOT_TOKEN'),
        'admin_ids'=>array_values(array_filter(preg_split('/[\s,;]+/',trim($adminIds)))),
        'admin_chat_id'=>$adminChat,
        'host'=>envv('DB_HOST',envv('MYSQLHOST','localhost')),
        'db'=>envv('DB_NAME',envv('MYSQLDATABASE')),
        'user'=>envv('DB_USER',envv('MYSQLUSER')),
        'pass'=>envv('DB_PASS',envv('MYSQLPASSWORD')),
    ];
    if($config['token']==='' || $config['db']==='' || $config['user']==='') installer();
}
$config['admin_ids']=array_map('strval',(array)($config['admin_ids']??[]));
if(($config['admin_chat_id']??'')==='') $config['admin_chat_id']=(string)($config['admin_ids'][0]??'');
$GLOBALS['_cfg']=$config;
try{schema();}catch(Throwable $ex){http_response_code(500);echo 'Database connection/setup failed: '.e($ex->getMessage());exit;}
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));$route=routeName();$key=(string)($_GET['key']??'');$token=(string)($config['token']??'');$authed=$token!==''&&$key!==''&&hash_equals($token,$key);
if($method==='GET'&&in_array($route,['setup','setwebhook','install'],true)){
    if(!$authed)jsonResponse(['ok'=>false,'error'=>'invalid key','usage'=>'?setup=1&key=BOT_TOKEN'],403);
    jsonResponse(setupWebhook(isset($_GET['url'])?(string)$_GET['url']:null));
}
if($method==='GET'&&$route==='status'){
    if(!$authed)jsonResponse(['ok'=>false,'error'=>'invalid key'],403);
    $me=tg('getMe');$hook=tg('getWebhookInfo');jsonResponse(['ok'=>!empty($me['ok']),'version'=>VERSION,'bot'=>isset($me['result'])?['id'=>$me['result']['id']??null,'username'=>$me['result']['username']??null,'name'=>$me['result']['first_name']??null]:null,'webhook'=>$hook['result']??null,'mysql'=>['users'=>(int)(one('SELECT COUNT(*) n FROM users')['n']??0),'checks'=>(int)(one('SELECT COUNT(*) n FROM checks')['n']??0),'buttons'=>(int)(one('SELECT COUNT(*) n FROM buttons')['n']??0)],'time'=>date(DATE_ATOM)]);
}
if($method==='GET'&&($route==='health'||isset($_GET['health'])))jsonResponse(['ok'=>true,'version'=>VERSION,'time'=>date(DATE_ATOM)]);
if($method==='GET'&&(isset($_GET['setup'])||isset($_GET['setwebhook'])||isset($_GET['install']))){if(!$authed)jsonResponse(['ok'=>false,'error'=>'invalid key'],403);jsonResponse(setupWebhook(isset($_GET['url'])?(string)$_GET['url']:null));}
if($method==='GET'&&isset($_GET['status'])){if(!$authed)jsonResponse(['ok'=>false,'error'=>'invalid key'],403);$me=tg('getMe');$hook=tg('getWebhookInfo');jsonResponse(['ok'=>!empty($me['ok']),'version'=>VERSION,'bot'=>$me['result']??null,'webhook'=>$hook['result']??null,'time'=>date(DATE_ATOM)]);}
if($method==='POST'){
    $isHook=in_array($route,['','telegram','webhook'],true)||str_starts_with($route,'telegram/');
    if(!$authed&&$isHook){$given=(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');$expected=(string)($config['webhook_secret']??webhookSecret($token));if($given===''||!hash_equals($expected,$given)){http_response_code(403);echo 'forbidden';exit;}}
    if(!$authed&&!$isHook){http_response_code(404);echo 'Not Found';exit;}
    $json=json_decode((string)file_get_contents('php://input'),true);if(!is_array($json)){http_response_code(400);echo 'bad request';exit;}
    try{processUpdate($json);}catch(Throwable $ex){error_log('UPDATE_FAIL '.$ex->getMessage());}
    http_response_code(200);echo 'OK';exit;
}
if($method==='GET'&&($route===''||$route===basename(strtolower((string)($_SERVER['SCRIPT_NAME']??'bot.php'))))){
    $hookInfo=tg('getWebhookInfo'); $expectedHook=baseUrl();
    if(!empty($config['token']) && (string)($hookInfo['result']['url']??'')!==$expectedHook) setupWebhook($expectedHook);
    header('Content-Type:text/html; charset=utf-8');echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e(setting('brand')).'</title><style>body{font-family:system-ui;background:#0b1220;color:#eef;display:grid;place-items:center;min-height:100vh}.card{padding:36px;text-align:center;background:#152238;border-radius:20px}.live{color:#67e3a0}</style><div class="card"><div style="font-size:42px">🤖</div><h1>'.e(setting('brand')).'</h1><p>Telegram webhook endpoint is active.</p><p>Version '.VERSION.'</p><p class="live">● Live</p></div>';exit;}
http_response_code(404);echo 'Not Found';
