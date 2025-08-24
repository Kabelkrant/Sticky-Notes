<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function valid_hex_color(string $c): bool { return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $c); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || !hash_equals($csrf, $_POST['csrf'])) {
        die('Invalid CSRF');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $title = trim($_POST['title']);
        $body = trim($_POST['body']);
        $color = valid_hex_color($_POST['color']) ? $_POST['color'] : '#FFF59D';
        $stmt = $pdo->prepare('INSERT INTO notes (title, body, color) VALUES (?, ?, ?)');
        $stmt->execute([$title, $body, $color]);
        header('Location: .'); exit;
    }
    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $title = trim($_POST['title']);
        $body = trim($_POST['body']);
        $color = valid_hex_color($_POST['color']) ? $_POST['color'] : '#FFF59D';
        $stmt = $pdo->prepare('UPDATE notes SET title=?, body=?, color=? WHERE id=?');
        $stmt->execute([$title, $body, $color, $id]);
        header('Location: .'); exit;
    }
    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM notes WHERE id=?');
        $stmt->execute([(int)$_POST['id']]);
        header('Location: .'); exit;
    }
    if ($action === 'move') {
        $stmt = $pdo->prepare('UPDATE notes SET pos_x=?, pos_y=? WHERE id=?');
        $stmt->execute([(int)$_POST['x'], (int)$_POST['y'], (int)$_POST['id']]);
        exit;
    }
}

$notes = $pdo->query('SELECT * FROM notes ORDER BY updated_at DESC')->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Sticky Notes</title>
  <link rel="stylesheet" href="styles.css">
  <link rel="icon" type="image/png" href="favicon.png">
</head>
<body>
  <header>
    <h1>🗒️ Sticky Notes</h1>
    <button id="newBtn">New</button>
  </header>

  <dialog id="newDialog">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="create">
      <input type="text" name="title" placeholder="Title" required>
      <input type="color" name="color" value="#FFF59D">
      <textarea name="body" placeholder="Note..." required></textarea>
      <menu>
        <button type="reset" id="cancelBtn">Cancel</button>
        <button type="submit">Save</button>
      </menu>
    </form>
  </dialog>

  <dialog id="editDialog">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="editId">
      <input type="text" name="title" id="editTitle" required>
      <input type="color" name="color" id="editColor" value="#FFF59D">
      <textarea name="body" id="editBody" required></textarea>
      <menu>
        <button type="button" id="cancelEdit">Cancel</button>
        <button type="submit">Save</button>
      </menu>
    </form>
  </dialog>

  <div class="board" id="board">
    <?php foreach ($notes as $n): ?>
      <div class="note" data-id="<?= (int)$n['id'] ?>" data-title="<?= e($n['title']) ?>" data-body="<?= e($n['body']) ?>" data-color="<?= e($n['color']) ?>" style="left:<?= (int)$n['pos_x'] ?>px; top:<?= (int)$n['pos_y'] ?>px; background:<?= e($n['color']) ?>;">
        <div class="note-actions">
          <button type="button" class="edit-btn" title="Modify">✏️</button>
          <form method="post" style="margin:0;">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
            <button type="submit" class="delete-btn" title="Delete">🗑️</button>
          </form>
        </div>
        <strong class="title-text"><?= e($n['title']) ?></strong>
        <p class="body-text"><?= nl2br(e($n['body'])) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

<script>
const board = document.getElementById('board');
const dialog = document.getElementById('newDialog');
const newBtn = document.getElementById('newBtn');
const cancelBtn = document.getElementById('cancelBtn');

const editDialog = document.getElementById('editDialog');
const cancelEdit = document.getElementById('cancelEdit');

newBtn.addEventListener('click', ()=> dialog.showModal());
cancelBtn.addEventListener('click', (e)=> { e.preventDefault(); dialog.close(); });
cancelEdit.addEventListener('click', ()=> editDialog.close());

// Open edit dialog
board.addEventListener('click', (e) => {
  const btn = e.target.closest('.edit-btn');
  if (!btn) return;
  const note = btn.closest('.note');
  document.getElementById('editId').value = note.dataset.id;
  document.getElementById('editTitle').value = note.dataset.title;
  document.getElementById('editBody').value = note.dataset.body;
  document.getElementById('editColor').value = note.dataset.color;
  editDialog.showModal();
});

// Confirmation prompt on deletion
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.delete-btn');
  if (!btn) return;
  if (!confirm('Are you sure you want to delete this Sticky Note?')) {
    e.preventDefault();
  }
});

let drag=null,start={x:0,y:0},origin={x:0,y:0};

function onPointerDown(e){
  const note=e.target.closest('.note'); if(!note) return;
  if(e.target.closest('.delete-btn')||e.target.closest('.edit-btn')) return; // niet slepen bij knoppen
  drag=note; const rect=note.getBoundingClientRect(); const boardRect=board.getBoundingClientRect();
  start.x=e.clientX; start.y=e.clientY;
  origin.x=rect.left-boardRect.left+board.scrollLeft;
  origin.y=rect.top-boardRect.top+board.scrollTop;
  e.preventDefault();
}
function onPointerMove(e){
  if(!drag) return;
  const dx=e.clientX-start.x, dy=e.clientY-start.y;
  const x=Math.max(0,Math.round(origin.x+dx));
  const y=Math.max(0,Math.round(origin.y+dy));
  drag.style.left=x+'px'; drag.style.top=y+'px';
}
async function onPointerUp(e){
  if(!drag) return;
  const id=drag.dataset.id, x=parseInt(drag.style.left)||0, y=parseInt(drag.style.top)||0;
  drag=null;
  const fd=new FormData();
  fd.append('csrf','<?= e($csrf) ?>');
  fd.append('action','move'); fd.append('id',id); fd.append('x',x); fd.append('y',y);
  await fetch(location.href,{method:'POST',body:fd});
}

board.addEventListener('mousedown',onPointerDown);
window.addEventListener('mousemove',onPointerMove);
window.addEventListener('mouseup',onPointerUp);
board.addEventListener('touchstart',e=>onPointerDown(e.touches[0]),{passive:false});
window.addEventListener('touchmove',e=>onPointerMove(e.touches[0]),{passive:false});
window.addEventListener('touchend',e=>onPointerUp(e.changedTouches[0]||e));
</script>
</body>
</html>
