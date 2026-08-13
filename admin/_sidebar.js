var token = localStorage.getItem('admin_token');
if (!token) { window.location.href = 'login.html'; }

function getHeaders() { return { 'Content-Type': 'application/json' }; }
function apiUrl(path) {
  var dir = location.pathname.substring(0, location.pathname.lastIndexOf('/'));
  var base = dir.substring(0, dir.lastIndexOf('/') + 1);
  var sep = path.indexOf('?') > -1 ? '&' : '?';
  return base + (path.charAt(0) === '/' ? path.substring(1) : path) + sep + 'token=' + encodeURIComponent(token);
}

function doLogout() {
  if (confirm('确定退出登录？')) {
    localStorage.removeItem('admin_token');
    window.location.href = 'login.html';
  }
}

function initSidebar(pageTitle, pageSub) {
  document.getElementById('pageTitle').textContent = pageTitle || '';
  document.getElementById('pageSub').textContent = pageSub || '';

  var path = window.location.pathname;
  var page = path.substring(path.lastIndexOf('/') + 1).replace('.html', '') || 'dashboard';
  var navItems = document.querySelectorAll('.nav-item');
  navItems.forEach(function(item) {
    var itemPage = item.getAttribute('data-page');
    if (itemPage === page) {
      item.classList.add('active');
    }
  });

  fetch(apiUrl('/api/auth.php?action=me'), { headers: getHeaders() })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.code === 0 && res.data) {
        var nameEl = document.getElementById('sidebarUsername');
        if (nameEl) nameEl.textContent = res.data.username || '管理员';
      }
    });
}

function toggleSidebar() {
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebarOverlay');
  if (sidebar && overlay) {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
  }
}

document.head.insertAdjacentHTML('beforeend', '<style>.sidebar-overlay{position:fixed;inset:0;background:rgba(6,6,14,0.6);z-index:99;backdrop-filter:blur(4px);display:none}.sidebar-overlay.active{display:block}@media(max-width:768px){.sidebar{transform:translateX(-100%);transition:transform .3s}.sidebar.open{transform:translateX(0)}}</style>');