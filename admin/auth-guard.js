// admin/auth-guard.js
// 登录守卫脚本 - admin/*.html 页面在 head 中引用此文件
// 未登录自动跳转到 login.html

(function() {
  var token = localStorage.getItem('admin_token');
  if (!token) {
    window.location.href = 'login.html';
    return;
  }

  var xhr = new XMLHttpRequest();
  var dir = location.pathname.substring(0, location.pathname.lastIndexOf('/'));
  var base = dir.substring(0, dir.lastIndexOf('/') + 1);
  xhr.open('GET', base + 'api/auth.php?action=me', true);
  xhr.setRequestHeader('Authorization', 'Bearer ' + token);
  xhr.onload = function() {
    if (xhr.status !== 200) {
      localStorage.removeItem('admin_token');
      window.location.href = 'login.html';
      return;
    }
    try {
      var res = JSON.parse(xhr.responseText);
      if (res.code !== 0) {
        localStorage.removeItem('admin_token');
        window.location.href = 'login.html';
      }
    } catch(e) {
      localStorage.removeItem('admin_token');
      window.location.href = 'login.html';
    }
  };
  xhr.onerror = function() {
    // 网络错误时不跳转，让页面继续加载
  };
  xhr.send();
})();
