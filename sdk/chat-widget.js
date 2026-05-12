// sdk/chat-widget.js
// 官网嵌入 JS SDK（可选功能）
// 客户在官网底部粘贴一行 script 标签即可使用

(function() {
  var config = {
    position: 'bottom-right',
    serverUrl: window.location.origin,
    themeColor: '#667eea',
  };

  if (window.ChatWidgetConfig) {
    Object.assign(config, window.ChatWidgetConfig);
  }

  var existing = document.getElementById('ai-kefu-widget');
  if (existing) return;

  var container = document.createElement('div');
  container.id = 'ai-kefu-widget';
  container.style.cssText = 'position:fixed;z-index:999999;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;';

  if (config.position === 'bottom-right') {
    container.style.cssText += 'bottom:20px;right:20px;';
  } else {
    container.style.cssText += 'bottom:20px;left:20px;';
  }

  var btn = document.createElement('button');
  btn.id = 'ai-kefu-btn';
  btn.style.cssText = 'width:56px;height:56px;border-radius:50%;border:none;background:' + config.themeColor + ';color:#fff;font-size:24px;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,0.2);transition:transform 0.2s;';
  btn.textContent = '💬';
  btn.onmouseover = function() { btn.style.transform = 'scale(1.1)'; };
  btn.onmouseout = function() { btn.style.transform = 'scale(1)'; };
  btn.onclick = function() {
    var chatUrl = config.serverUrl + '/chat.html';
    var w = Math.min(400, window.innerWidth - 40);
    var h = Math.min(700, window.innerHeight - 40);
    var left = (window.innerWidth - w) / 2;
    var top = (window.innerHeight - h) / 2;
    window.open(chatUrl, 'ai_kefu', 'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top + ',scrollbars=no');
  };

  container.appendChild(btn);
  document.body.appendChild(container);
})();
