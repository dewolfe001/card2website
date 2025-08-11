<?php
// edit_site.php
session_start();

/** Simple CSRF nonce */
if (empty($_SESSION['editor_nonce'])) {
    $_SESSION['editor_nonce'] = bin2hex(random_bytes(16));
}
$nonce = $_SESSION['editor_nonce'];

// The page to edit (use your existing viewer that outputs the HTML you want to edit)
// If your current page is already https://businesscard2website.com/view_site.php?id=47,
// just point the iframe there.
$id = isset($_GET['id']) ? (int) $_GET['id'] : -1;
$iframeSrc = "/generated_sites/{$id}.html?v=" . time();

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Inline Editor</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root { --ui: #0f172a; --bg:#f8fafc; --accent:#2563eb; --muted:#e5e7eb; }
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Inter, Helvetica, Arial, sans-serif; margin:0; background:var(--bg); color:var(--ui); }
  .toolbar {
    position: sticky; top: 0; z-index: 1000; background: #fff; border-bottom: 1px solid var(--muted);
    display: flex; flex-wrap: wrap; gap: 8px; padding: 10px 12px; align-items: center;
  }
  .toolbar button, .toolbar .right > * {
    border: 1px solid var(--muted); background:#fff; padding:8px 10px; border-radius:10px; cursor:pointer;
  }
  .toolbar button:hover { border-color: var(--accent); }
  .toolbar .right { margin-left: auto; display:flex; gap:8px; align-items:center; }
  .status { font-size: 12px; color:#475569; }
  .wrap { height: calc(100vh - 60px); }
  iframe { width: 100%; height: 100%; border: 0; background: #fff; }
  .inline-tip { font-size:12px; color:#64748b; margin-left:6px; }
  .source-area { display:none; width:100%; height: 40vh; }
  .pill { padding: 6px 10px; border-radius: 999px; background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe;}
</style>
</head>
<body>
  <div class="toolbar">
    <!-- Formatting -->
    <button type="button" data-cmd="bold"><b>B</b></button>
    <button type="button" data-cmd="italic"><i>I</i></button>
    <button type="button" data-cmd="underline"><u>U</u></button>
    <button type="button" data-cmd="insertUnorderedList">• List</button>
    <button type="button" data-cmd="insertOrderedList">1. List</button>
    <button type="button" data-heading="p">P</button>
    <button type="button" data-heading="h1">H1</button>
    <button type="button" data-heading="h2">H2</button>
    <button type="button" data-heading="h3">H3</button>
    <button type="button" id="mkLink">Link</button>
    <button type="button" id="rmLink">Unlink</button>
    <span class="inline-tip">Tip: Click any image or background to replace it.</span>

    <div class="right">
      <button type="button" id="toggleEdit" class="pill">Editing: ON</button>
      <button type="button" id="toggleSource">Source</button>
      <button type="button" id="undo">Undo</button>
      <button type="button" id="redo">Redo</button>
      <button type="button" id="saveBtn" style="background:var(--accent);color:#fff;border-color:var(--accent)">Save</button>
      <span id="status" class="status">Ready</span>
    </div>
  </div>

  <div class="wrap">
    <iframe id="siteFrame" src="<?php echo htmlspecialchars($iframeSrc, ENT_QUOTES); ?>" referrerpolicy="no-referrer"></iframe>
  </div>

  <textarea id="sourceArea" class="source-area"></textarea>

  <!-- Hidden inputs for image uploads -->
  <input type="file" id="imagePicker" accept="image/*" style="display:none" />
  <script>
    (function(){
      const iframe = document.getElementById('siteFrame');
      const statusEl = document.getElementById('status');
      const imagePicker = document.getElementById('imagePicker');
      const toggleEditBtn = document.getElementById('toggleEdit');
      const toggleSourceBtn = document.getElementById('toggleSource');
      const sourceArea = document.getElementById('sourceArea');
      let editEnabled = true;
      let lastClicked = null; // { el, type: 'img' | 'bg' }
      let doc; // iframe document
      let isSource = false;

      // Helper: set status message
      function setStatus(msg) { statusEl.textContent = msg; }

      function getBackgroundEl(startEl) {
        if (!doc) return null;
        let el = startEl;
        while (el && el !== doc.body) {
          const bg = doc.defaultView.getComputedStyle(el).backgroundImage;
          if (bg && bg !== 'none' && bg.includes('url(')) return el;
          el = el.parentElement;
        }
        return null;
      }

      // Wait for iframe to load, then make editable & wire up events
      iframe.addEventListener('load', () => {
        try {
          doc = iframe.contentDocument || iframe.contentWindow.document;

          // Same-origin guard
          // Accessing doc.title is a quick test that will throw if cross-origin
          void doc.title;

          // Make body editable
          doc.body.setAttribute('contenteditable', 'true');
          doc.body.style.caretColor = '#000';

          // Prevent navigation while editing (clicking links)
          doc.addEventListener('click', (e) => {
            const a = e.target.closest('a');
            if (a && editEnabled) {
              e.preventDefault();
              setStatus('Link click blocked during edit mode.');
            }
          }, true);

          // Click-to-replace images/backgrounds
          doc.addEventListener('click', (e) => {
            if (!editEnabled) return;
            const img = e.target.closest('img');
            const bgEl = img ? null : getBackgroundEl(e.target);
            const target = img || bgEl;
            if (target) {
              e.preventDefault();
              lastClicked = { el: target, type: img ? 'img' : 'bg' };
              imagePicker.click();
            }
          });

          // Drag & drop image/background replace
          doc.addEventListener('dragover', (e) => {
            if (!editEnabled) return;
            const img = e.target.closest('img');
            const bgEl = img ? null : getBackgroundEl(e.target);
            if (img || bgEl) { e.preventDefault(); }
          });
          doc.addEventListener('drop', (e) => {
            if (!editEnabled) return;
            const img = e.target.closest('img');
            const bgEl = img ? null : getBackgroundEl(e.target);
            const target = img || bgEl;
            if (!target) return;
            e.preventDefault();
            lastClicked = { el: target, type: img ? 'img' : 'bg' };
            const file = e.dataTransfer.files && e.dataTransfer.files[0];
            if (file && file.type.startsWith('image/')) {
              uploadAndSwap(file, lastClicked);
            }
          });

          // Normalize pasted content (strip styles)
          doc.addEventListener('paste', (e) => {
            if (!editEnabled) return;
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text/plain');
            doc.execCommand('insertText', false, text);
          });

          setStatus('Editor ready.');
        } catch (err) {
          console.error(err);
          setStatus('Cannot edit: iframe must be same-origin.');
        }
      });

      // Toolbar: execCommand helpers (still widely supported for simple tasks)
      document.querySelectorAll('[data-cmd]').forEach(btn => {
        btn.addEventListener('click', () => {
          if (!doc || !editEnabled) return;
          doc.execCommand(btn.dataset.cmd, false, null);
          iframe.contentWindow.focus();
        });
      });

      // Headings
      document.querySelectorAll('[data-heading]').forEach(btn => {
        btn.addEventListener('click', () => {
          if (!doc || !editEnabled) return;
          const tag = btn.dataset.heading.toUpperCase();
          // Toggle block format by wrapping selection
          doc.execCommand('formatBlock', false, tag);
          iframe.contentWindow.focus();
        });
      });

      // Link / unlink
      document.getElementById('mkLink').addEventListener('click', () => {
        if (!doc || !editEnabled) return;
        const url = prompt('Enter URL (https://...)');
        if (url) doc.execCommand('createLink', false, url);
      });
      document.getElementById('rmLink').addEventListener('click', () => {
        if (!doc || !editEnabled) return;
        doc.execCommand('unlink', false, null);
      });

      // Undo/redo
      document.getElementById('undo').addEventListener('click', () => { if (doc) doc.execCommand('undo', false, null); });
      document.getElementById('redo').addEventListener('click', () => { if (doc) doc.execCommand('redo', false, null); });

      // Toggle edit mode
      toggleEditBtn.addEventListener('click', () => {
        editEnabled = !editEnabled;
        if (doc) doc.body.setAttribute('contenteditable', editEnabled ? 'true' : 'false');
        toggleEditBtn.textContent = `Editing: ${editEnabled ? 'ON' : 'OFF'}`;
        toggleEditBtn.style.background = editEnabled ? '#eefce8' : '#fee2e2';
        toggleEditBtn.style.color = editEnabled ? '#166534' : '#991b1b';
      });

      // Toggle source view
      toggleSourceBtn.addEventListener('click', () => {
        if (!doc) return;
        isSource = !isSource;
        if (isSource) {
          sourceArea.value = doc.documentElement.outerHTML;
          sourceArea.style.display = 'block';
          setStatus('Source view ON (editing full HTML).');
        } else {
          const newHtml = sourceArea.value;
          // Replace iframe content with user-edited source
          const parser = new DOMParser();
          const parsed = parser.parseFromString(newHtml, 'text/html');
          doc.open(); doc.write(parsed.documentElement.outerHTML); doc.close();
          // Re-init editable bits after replacing doc
          doc = iframe.contentDocument || iframe.contentWindow.document;
          doc.body.setAttribute('contenteditable', 'true');
          sourceArea.style.display = 'none';
          setStatus('Source applied.');
        }
      });

      // Image picker -> upload
      imagePicker.addEventListener('change', async (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file || !lastClicked) return;
        await uploadAndSwap(file, lastClicked);
        imagePicker.value = '';
        lastClicked = null;
      });

      async function uploadAndSwap(file, target) {
        setStatus('Uploading image...');
        try {
          const form = new FormData();
          form.append('image', file);
          form.append('nonce', '<?php echo $nonce; ?>');
          form.append('site_id', '<?php echo $id; ?>');

          const resp = await fetch('/upload_image.php', { method: 'POST', body: form, credentials: 'same-origin' });
          const json = await resp.json();
          if (!resp.ok || !json.success) throw new Error(json.error || 'Upload failed');

          const newUrl = json.url + '?t=' + Date.now();
          if (target.type === 'img') {
            const imgEl = target.el;
            // Keep width/height attributes if present
            const priorW = imgEl.getAttribute('width');
            const priorH = imgEl.getAttribute('height');

            imgEl.setAttribute('src', newUrl);
            if (priorW) imgEl.setAttribute('width', priorW);
            if (priorH) imgEl.setAttribute('height', priorH);
          } else if (target.type === 'bg') {
            target.el.style.backgroundImage = `url('${newUrl}')`;
          }

          setStatus('Image replaced.');
        } catch (err) {
          console.error(err);
          alert('Upload error: ' + err.message);
          setStatus('Upload failed.');
        }
      }

      // Save HTML back to server
      document.getElementById('saveBtn').addEventListener('click', async () => {
        if (!doc) return;
        setStatus('Saving...');
        // Prefer full document HTML (so head/meta/styles are preserved)
        const html = doc.documentElement.outerHTML;
        try {
          const resp = await fetch('/save_html.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
              id: <?php echo $id; ?>,
              nonce: '<?php echo $nonce; ?>',
              html
            })
          });
          const json = await resp.json();
          if (!resp.ok || !json.success) throw new Error(json.error || 'Save failed');
          setStatus('Saved ✓');
        } catch (err) {
          console.error(err);
          alert('Save error: ' + err.message);
          setStatus('Save failed.');
        }
      });
    })();
  </script>
</body>
</html>
