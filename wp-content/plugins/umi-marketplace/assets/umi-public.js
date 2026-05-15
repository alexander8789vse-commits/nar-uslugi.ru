(function () {
  'use strict';

  function $(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  function chatInit(root) {
    if (!root) return;
    var thread = root.getAttribute('data-thread');
    if (!thread) return;

    var isDispute = root.getAttribute('data-umi-dispute') === '1';
    var log = $('.umi-chat-log', root);
    var form = $('.umi-chat-form', root);
    var lastId = 0;
    var pollMs = window.umiMp && window.umiMp.pollMs ? parseInt(window.umiMp.pollMs, 10) : 5000;

    function renderMessage(m) {
      if (m && m.id && log.querySelector('.umi-chat-msg[data-id="' + m.id + '"]')) {
        return;
      }
      var div = document.createElement('div');
      div.className = 'umi-chat-msg' + (m.is_mine ? ' umi-chat-msg--mine' : '');
      div.setAttribute('data-id', String(m.id));
      div.innerHTML =
        '<div class="umi-chat-msg-meta">' +
        (m.is_mine ? '' : '<span class="umi-chat-msg-author"></span>') +
        '</div>' +
        '<div class="umi-chat-msg-body"></div>';
      if (!m.is_mine) {
        $('.umi-chat-msg-author', div).textContent = m.sender || '';
      }
      var bodyEl = $('.umi-chat-msg-body', div);
      if (m.attachment_url) {
        var fig = document.createElement('div');
        fig.className = 'umi-chat-msg-attach';
        var im = document.createElement('img');
        im.src = m.attachment_url;
        im.alt = '';
        im.loading = 'lazy';
        im.className = 'umi-chat-msg-img';
        fig.appendChild(im);
        bodyEl.appendChild(fig);
      }
      if (m.body && String(m.body).trim()) {
        var p = document.createElement('div');
        p.className = 'umi-chat-msg-text';
        p.innerHTML = m.body;
        bodyEl.appendChild(p);
      }
      log.appendChild(div);
      lastId = Math.max(lastId, m.id);
      log.scrollTop = log.scrollHeight;
    }

    function poll() {
      var fd = new FormData();
      fd.append('action', 'umi_mp_chat_poll');
      fd.append('nonce', window.umiMp.nonce);
      fd.append('thread_id', thread);
      fd.append('after_id', String(lastId));
      fetch(window.umiMp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) return;
          var data = json.data || {};
          (data.messages || []).forEach(renderMessage);
          if (typeof data.unread === 'number') {
            document.querySelectorAll('.umi-chat-badge').forEach(function (el) {
              el.textContent = data.unread > 0 ? String(data.unread) : '';
              el.setAttribute('data-umi-unread', String(data.unread));
              el.classList.toggle('umi-chat-badge--empty', data.unread < 1);
            });
          }
        })
        .catch(function () {});
    }

    setInterval(poll, pollMs);
    poll();

    if (isDispute && form && window.umiMp && window.umiMp.uploadNonce) {
      var fileIn = form.querySelector('.umi-dispute-file');
      var attHid = form.querySelector('.umi-dispute-attachment-id');
      var prev = form.querySelector('.umi-dispute-attach-preview');
      var clr = form.querySelector('.umi-dispute-attach-clear');
      function setAttach(id, url) {
        if (attHid) attHid.value = id ? String(id) : '';
        if (prev) {
          if (url) {
            prev.innerHTML = '<img src="' + url + '" alt="" class="umi-dispute-thumb" />';
            prev.removeAttribute('hidden');
          } else {
            prev.innerHTML = '';
            prev.setAttribute('hidden', 'hidden');
          }
        }
        if (clr) clr.toggleAttribute('hidden', !id);
        if (fileIn) fileIn.value = '';
      }
      if (fileIn) {
        fileIn.addEventListener('change', function () {
          if (!fileIn.files || !fileIn.files[0]) return;
          var ufd = new FormData();
          ufd.append('action', 'umi_mp_dispute_upload');
          ufd.append('nonce', window.umiMp.uploadNonce);
          ufd.append('thread_id', thread);
          ufd.append('file', fileIn.files[0]);
          fileIn.value = '';
          fetch(window.umiMp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: ufd })
            .then(function (r) {
              return r.json();
            })
            .then(function (json) {
              if (!json || !json.success || !json.data) return;
              setAttach(json.data.id, json.data.url || '');
            })
            .catch(function () {});
        });
      }
      if (clr) {
        clr.addEventListener('click', function () {
          setAttach(0, '');
        });
      }
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var ta = form.querySelector('textarea[name="message"]');
        var attHid = form.querySelector('.umi-dispute-attachment-id');
        var attId = attHid && attHid.value ? parseInt(attHid.value, 10) : 0;
        var text = ta ? ta.value.trim() : '';
        if (!text && (!isDispute || !attId)) return;
        var fd = new FormData();
        fd.append('action', 'umi_mp_chat_send');
        fd.append('nonce', window.umiMp.nonce);
        fd.append('thread_id', thread);
        fd.append('message', ta ? ta.value : '');
        if (attId > 0) fd.append('attachment_id', String(attId));
        fetch(window.umiMp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            if (!json || !json.success) return;
            if (ta) ta.value = '';
            if (isDispute && attHid) {
              attHid.value = '';
              var prev = form.querySelector('.umi-dispute-attach-preview');
              var clr = form.querySelector('.umi-dispute-attach-clear');
              if (prev) {
                prev.innerHTML = '';
                prev.setAttribute('hidden', 'hidden');
              }
              if (clr) clr.setAttribute('hidden', 'hidden');
            }
            if (json.data && json.data.id) {
              var nid = parseInt(json.data.id, 10);
              if (!isNaN(nid) && nid > 0) {
                lastId = Math.max(lastId, nid);
              }
            }
            poll();
          })
          .catch(function () {});
      });
    }
  }

  function badgePoll() {
    if (!window.umiMp || !window.umiMp.nonce) return;
    var fd = new FormData();
    fd.append('action', 'umi_mp_unread');
    fd.append('nonce', window.umiMp.nonce);
    fetch(window.umiMp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) {
        return r.json();
      })
      .then(function (json) {
        if (!json || !json.success) return;
        var n = json.data && typeof json.data.unread === 'number' ? json.data.unread : 0;
        document.querySelectorAll('.umi-chat-badge').forEach(function (el) {
          el.textContent = n > 0 ? String(n) : '';
          el.setAttribute('data-umi-unread', String(n));
          el.classList.toggle('umi-chat-badge--empty', n < 1);
        });
      })
      .catch(function () {});
  }

  function cabinetUploadInit() {
    if (!window.umiMp || !window.umiMp.uploadNonce) return;
    document.querySelectorAll('.umi-cabinet-upload').forEach(function (wrap) {
      var fileInput = wrap.querySelector('input.umi-cabinet-file') || wrap.querySelector('input[type="file"]');
      var hidden = wrap.querySelector('input.umi-cabinet-attachment-id');
      var preview = wrap.querySelector('.umi-cabinet-upload-preview');
      var previewImg = preview ? preview.querySelector('img') : null;
      var clearBtn = wrap.querySelector('.umi-cabinet-upload-clear');
      if (!fileInput || !hidden) return;
      function showClear(on) {
        if (!clearBtn) return;
        if (on) {
          clearBtn.removeAttribute('hidden');
          clearBtn.style.display = '';
        } else {
          clearBtn.setAttribute('hidden', 'hidden');
          clearBtn.style.display = 'none';
        }
      }
      function setPreview(url) {
        if (!preview || !previewImg) return;
        if (url) {
          previewImg.src = url;
          preview.removeAttribute('hidden');
          showClear(true);
        } else {
          preview.setAttribute('hidden', 'hidden');
          previewImg.removeAttribute('src');
          showClear(false);
        }
      }
      var clearFlag = wrap.closest('.umi-cabinet-profile-form')
        ? wrap.closest('.umi-cabinet-profile-form').querySelector('.umi-profile-clear-flag')
        : null;
      function clear() {
        hidden.value = '';
        setPreview('');
        fileInput.value = '';
        if (clearFlag) clearFlag.value = '1';
      }
      fileInput.addEventListener('change', function () {
        if (!fileInput.files || !fileInput.files[0]) return;
        var fd = new FormData();
        fd.append('action', 'umi_mp_upload');
        fd.append('nonce', window.umiMp.uploadNonce);
        fd.append('file', fileInput.files[0]);
        fileInput.value = '';
        fetch(window.umiMp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            if (!json || !json.success || !json.data) return;
            hidden.value = String(json.data.id);
            setPreview(json.data.url || '');
            if (clearFlag) clearFlag.value = '0';
          })
          .catch(function () {});
      });
      if (clearBtn) {
        clearBtn.addEventListener('click', function (e) {
          e.preventDefault();
          clear();
        });
      }
    });
  }

  function cabinetPanelsInit() {
    var r = document.querySelector('#umi-cabinet [data-umi-cabinet-panels]');
    if (!r) return;
    var t = r.querySelectorAll('[data-umi-cabinet-tab]');
    var p = r.querySelectorAll('[data-umi-cabinet-panel]');
    t.forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-umi-cabinet-tab');
        t.forEach(function (x) {
          x.classList.toggle('is-active', x === b);
          x.setAttribute('aria-selected', x === b ? 'true' : 'false');
        });
        p.forEach(function (el) {
          var on = el.getAttribute('data-umi-cabinet-panel') === k;
          el.classList.toggle('is-active', on);
          el.toggleAttribute('hidden', !on);
        });
      });
    });
  }

  function cabinetProductAuthorInit() {
    document.querySelectorAll('[data-umi-product-author]').forEach(function (wrap) {
      var author = wrap.querySelector('#umi_p_author');
      var pay = wrap.querySelector('#umi_p_pay_shares');
      if (!author || !pay) return;
      var form = wrap.closest('form');
      var priceLabel = form ? form.querySelector('#umi_p_price_label') : null;
      var i18n = (window.umiMp && window.umiMp.i18n) || {};
      function updatePriceLabel() {
        if (!priceLabel) return;
        if (!author.checked) {
          priceLabel.textContent =
            i18n.cabinetPriceNonAuthor || 'Можно купить только за доли';
        } else if (pay.checked) {
          priceLabel.textContent =
            i18n.cabinetPriceBoth || 'Купить можно за доли и рубли';
        } else {
          priceLabel.textContent = i18n.cabinetPriceRub || 'Стоимость, ₽';
        }
      }
      function sync() {
        var on = author.checked;
        pay.disabled = !on;
        var hidden = wrap.querySelector('input[data-umi-pay-shares-fallback]');
        if (!on) {
          pay.checked = true;
          pay.removeAttribute('name');
          if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.setAttribute('data-umi-pay-shares-fallback', '1');
            hidden.name = 'umi_p_pay_shares';
            hidden.value = '1';
            wrap.appendChild(hidden);
          }
        } else {
          if (hidden) hidden.remove();
          pay.setAttribute('name', 'umi_p_pay_shares');
        }
        updatePriceLabel();
      }
      author.addEventListener('change', sync);
      pay.addEventListener('change', updatePriceLabel);
      sync();
    });
  }

  function favoritesInit() {
    if (!window.umiMp || !window.umiMp.nonce) return;
    document.querySelectorAll('.umi-fav-btn[data-umi-fav]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var id = btn.getAttribute('data-umi-fav');
        if (!id) return;
        var fd = new FormData();
        fd.append('action', 'umi_mp_favorite');
        fd.append('nonce', window.umiMp.nonce);
        fd.append('post_id', id);
        btn.disabled = true;
        fetch(window.umiMp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            btn.disabled = false;
            if (!json || !json.success || !json.data) return;
            var on = !!json.data.favorited;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            var i18n = (window.umiMp && window.umiMp.i18n) || {};
            var title = on
              ? i18n.favRemove || 'Убрать из избранного'
              : i18n.favAdd || 'В избранное';
            btn.setAttribute('title', title);
            btn.setAttribute('aria-label', title);
            var sr = btn.querySelector('.screen-reader-text');
            if (sr) sr.textContent = title;
          })
          .catch(function () {
            btn.disabled = false;
          });
      });
    });
  }

  // Modal open/close — runs immediately (only attaches document-level listeners).
  (function () {
    function openModal(id) {
      var modal = document.getElementById(id);
      if (!modal) return;
      modal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
      var first = modal.querySelector('button,input:not([type="hidden"]),textarea,select,a[href]');
      if (first) setTimeout(function () { first.focus(); }, 40);
    }
    function closeModal(modal) {
      if (!modal) return;
      modal.setAttribute('hidden', 'hidden');
      if (!document.querySelector('.umi-modal:not([hidden])')) {
        document.body.style.overflow = '';
      }
    }
    document.addEventListener('click', function (e) {
      var openBtn = e.target.closest('[data-umi-open-modal]');
      if (openBtn) { openModal(openBtn.getAttribute('data-umi-open-modal')); return; }
      var closeEl = e.target.closest('[data-umi-close-modal]');
      if (closeEl) { closeModal(closeEl.closest('.umi-modal')); return; }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var open = document.querySelector('.umi-modal:not([hidden])');
      if (open) closeModal(open);
    });
  }());

  function cabinetThreadDeleteInit() {
    if (!window.umiMp || !window.umiMp.nonce) return;
    document.querySelectorAll('[data-umi-delete-thread]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var tid = btn.getAttribute('data-umi-delete-thread');
        if (!tid) return;
        var msg =
          (window.umiMp.i18n && window.umiMp.i18n.deleteThread) ||
          'Delete this conversation?';
        if (!window.confirm(msg)) return;
        var fd = new FormData();
        fd.append('action', 'umi_mp_chat_delete_thread');
        fd.append('nonce', window.umiMp.nonce);
        fd.append('thread_id', tid);
        btn.disabled = true;
        fetch(window.umiMp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              btn.disabled = false;
              return;
            }
            var li = btn.closest('.umi-cabinet-threads__item');
            if (li) li.remove();
            if (typeof json.data === 'object' && json.data && typeof json.data.unread === 'number') {
              var n = json.data.unread;
              document.querySelectorAll('.umi-chat-badge').forEach(function (el) {
                el.textContent = n > 0 ? String(n) : '';
                el.setAttribute('data-umi-unread', String(n));
                el.classList.toggle('umi-chat-badge--empty', n < 1);
              });
            }
            var ul = document.querySelector('#umi-cabinet .umi-cabinet-threads');
            if (ul && !ul.querySelector('.umi-cabinet-threads__item')) {
              var empty = document.createElement('p');
              empty.className = 'umi-cabinet-empty';
              empty.textContent =
                (window.umiMp.i18n && window.umiMp.i18n.noThreads) ||
                '';
              if (empty.textContent && ul.parentNode) {
                ul.parentNode.replaceChild(empty, ul);
              }
            }
          })
          .catch(function () {
            btn.disabled = false;
          });
      });
    });
  }

  function shareInit() {
    document.querySelectorAll('[data-umi-share]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var url = btn.dataset.umiShareUrl || window.location.origin;
        if (navigator.share) {
          navigator.share({ url: url }).catch(function () {});
        } else if (navigator.clipboard) {
          navigator.clipboard.writeText(url).then(function () {
            var orig = btn.title;
            btn.title = 'Скопировано!';
            setTimeout(function () { btn.title = orig; }, 2000);
          }).catch(function () {});
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.umi-chat').forEach(chatInit);
    if (document.querySelector('.umi-chat-badge') && window.umiMp) {
      var ms = window.umiMp.pollMs ? parseInt(window.umiMp.pollMs, 10) : 5000;
      setInterval(badgePoll, ms);
      badgePoll();
    }
    cabinetUploadInit();
    cabinetPanelsInit();
    cabinetProductAuthorInit();
    cabinetThreadDeleteInit();
    favoritesInit();
    shareInit();
  });
})();
