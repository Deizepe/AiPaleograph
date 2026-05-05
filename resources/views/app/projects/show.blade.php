@extends('layouts/contentNavbarLayout')

@section('title', $project->name)

@section('vendor-style')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/dataTables.bootstrap5.min.css" />
@endsection

{{-- Do not load DataTables here: Vite loads jQuery as a deferred module, so sync CDN scripts would run too early. --}}

@section('page-script')
<script>
(function () {
  const pagesDataUrl = @json(route('projects.pages.data', $project));
  const DT_CDN = 'https://cdn.datatables.net/1.13.11/js/';
  const TINYMCE_CDN = 'https://cdn.jsdelivr.net/npm/tinymce@6.8.4/tinymce.min.js';

  function escapeHtml(s) {
    if (s == null || s === '') return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function hasHtmlTags(s) {
    return /<\/?[a-z][\s\S]*>/i.test(String(s || ''));
  }

  function decodeHtmlEntities(s) {
    var el = document.createElement('textarea');
    el.innerHTML = String(s || '');
    return el.value;
  }

  /**
   * Load transcribed text into TinyMCE with line breaks visible.
   * Plain newlines, <br>, and block tags become visual breaks; inline tags (bold, links) are kept.
   */
  function flattenToLineBreakHtml(value) {
    var raw = decodeHtmlEntities(String(value || ''));
    if (!raw.trim()) return '';
    raw = raw.replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\\n/g, '\n');
    if (!hasHtmlTags(raw)) {
      return raw.replace(/\n/g, '<br>');
    }
    var tpl = document.createElement('template');
    tpl.innerHTML = raw.trim();
    var out = [];
    function emitBreak() {
      if (!out.length) return;
      if (out[out.length - 1] !== '<br>') out.push('<br>');
    }
    function walk(node) {
      if (node.nodeType === Node.TEXT_NODE) {
        if (node.textContent) out.push(node.textContent);
        return;
      }
      if (node.nodeType !== Node.ELEMENT_NODE) return;
      var tag = node.tagName.toLowerCase();
      if (tag === 'br') {
        emitBreak();
        return;
      }
      if (tag === 'p' || tag === 'div' || /^h[1-6]$/.test(tag) || tag === 'li' || tag === 'blockquote') {
        for (var c = node.firstChild; c; c = c.nextSibling) walk(c);
        emitBreak();
        return;
      }
      if (node.outerHTML) out.push(node.outerHTML);
    }
    for (var n = tpl.content.firstChild; n; n = n.nextSibling) walk(n);
    var joined = out.join('');
    joined = joined.replace(/(?:<br\s*\/?>(?:\s|\u00a0)*)+$/gi, '');
    return joined || raw.replace(/\n/g, '<br>');
  }

  function csrfToken() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = false;
      s.onload = resolve;
      s.onerror = function () { reject(new Error('Failed to load ' + src)); };
      document.body.appendChild(s);
    });
  }

  function ensureTinyMce() {
    if (window.tinymce) {
      return Promise.resolve(window.tinymce);
    }
    return loadScript(TINYMCE_CDN).then(function () {
      return window.tinymce;
    });
  }

  /** Wait for Vite bundle to assign window.jQuery, then load DataTables (which patches that jQuery). */
  function ensureDataTables() {
    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.dataTable) {
      return Promise.resolve();
    }
    return new Promise(function (resolve, reject) {
      var attempts = 0;
      function waitJq() {
        if (window.jQuery && window.jQuery.fn) {
          loadScript(DT_CDN + 'jquery.dataTables.min.js')
            .then(function () { return loadScript(DT_CDN + 'dataTables.bootstrap5.min.js'); })
            .then(resolve)
            .catch(reject);
          return;
        }
        attempts += 1;
        if (attempts > 400) {
          reject(new Error('jQuery did not become available (check Vite / console).'));
          return;
        }
        setTimeout(waitJq, 25);
      }
      waitJq();
    });
  }

  function initTable() {
    var $ = window.jQuery;
    var el = document.getElementById('pages-table');
    if (!el || $.fn.dataTable.isDataTable(el)) return;
    var editModalEl = document.getElementById('editPageModal');
    var editModal = editModalEl
      ? (bootstrap.Modal.getInstance(editModalEl) || new bootstrap.Modal(editModalEl, { keyboard: true }))
      : null;
    var editForm = document.getElementById('editPageForm');
    var saveBtn = document.getElementById('editPageSaveBtn');
    var editPreviewImg = document.getElementById('editPagePreviewImage');
    var editPreviewWrap = document.querySelector('#editPageModal .editor-image-wrap');
    var zoomInBtn = document.getElementById('editImageZoomInBtn');
    var zoomOutBtn = document.getElementById('editImageZoomOutBtn');
    var zoomResetBtn = document.getElementById('editImageZoomResetBtn');
    var zoomLabel = document.getElementById('editImageZoomLabel');
    var rerunModalEl = document.getElementById('rerunConfirmModal');
    var rerunModal = rerunModalEl
      ? (bootstrap.Modal.getInstance(rerunModalEl) || new bootstrap.Modal(rerunModalEl, { keyboard: true }))
      : null;
    var rerunForm = document.getElementById('rerunConfirmForm');
    var currentRow = null;
    var zoomScale = 1;
    var minZoom = 0.4;
    var maxZoom = 5;
    var panX = 0;
    var panY = 0;
    var dragActive = false;
    var dragStartX = 0;
    var dragStartY = 0;
    var dragOriginX = 0;
    var dragOriginY = 0;
    var tinyReadyPromise = null;

    function clamp(v, lo, hi) {
      return Math.max(lo, Math.min(hi, v));
    }

    function applyZoom() {
      if (!editPreviewImg) return;
      editPreviewImg.style.transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + zoomScale + ')';
      if (zoomLabel) zoomLabel.textContent = Math.round(zoomScale * 100) + '%';
    }

    function setZoom(value) {
      zoomScale = clamp(value, minZoom, maxZoom);
      applyZoom();
    }

    function setDragging(active) {
      dragActive = active;
      if (editPreviewWrap) {
        editPreviewWrap.classList.toggle('is-dragging', active);
      }
    }

    function resetView() {
      panX = 0;
      panY = 0;
      setZoom(1);
    }

    function createTinyEditor(selector, extra) {
      if (!window.tinymce) return Promise.resolve(null);
      if (window.tinymce.get(selector.replace('#', ''))) {
        return Promise.resolve(window.tinymce.get(selector.replace('#', '')));
      }
      var base = {
        selector: selector,
        menubar: false,
        statusbar: false,
        branding: false,
        promotion: false,
        height: 220,
        plugins: 'link lists',
        toolbar: 'bold italic underline | link | removeformat',
        toolbar_mode: 'sliding',
        convert_urls: false,
        license_key: 'gpl',
        setup: function (editor) {
          editor.on('keydown', function (e) {
            if (e.key !== 'Escape' && e.keyCode !== 27) return;
            if (!editModalEl || !editModalEl.classList.contains('show')) return;
            e.preventDefault();
            if (editModal) editModal.hide();
          });
        },
      };
      return window.tinymce.init(Object.assign({}, base, extra || {})).then(function (editors) {
        return editors && editors.length ? editors[0] : null;
      });
    }

    function ensureEditorsInitialized() {
      if (tinyReadyPromise) return tinyReadyPromise;
      tinyReadyPromise = ensureTinyMce().then(function () {
        return Promise.all([
          createTinyEditor('#edit-original-text'),
          createTinyEditor('#edit-transcribed-text', { newline_behavior: 'linebreak' }),
          createTinyEditor('#edit-notes'),
        ]);
      });
      return tinyReadyPromise;
    }

    function setEditorOrTextareaValue(fieldName, value) {
      var idMap = {
        original_text: 'edit-original-text',
        transcribed_text: 'edit-transcribed-text',
        notes: 'edit-notes'
      };
      var id = idMap[fieldName];
      var editor = window.tinymce ? window.tinymce.get(id) : null;
      if (editor) {
        var content = value || '';
        if (fieldName === 'transcribed_text') {
          content = flattenToLineBreakHtml(content);
        } else if (fieldName === 'original_text' || fieldName === 'notes') {
          if (!hasHtmlTags(content)) {
            content = decodeHtmlEntities(content).replace(/\r?\n/g, '<br>');
          }
        }
        editor.setContent(content);
        return;
      }
      var el = editForm ? editForm.querySelector('[name="' + fieldName + '"]') : null;
      if (el) el.value = value || '';
    }

    function getEditorOrTextareaValue(fieldName) {
      var idMap = {
        original_text: 'edit-original-text',
        transcribed_text: 'edit-transcribed-text',
        notes: 'edit-notes'
      };
      var id = idMap[fieldName];
      var editor = window.tinymce ? window.tinymce.get(id) : null;
      if (editor) {
        var content = editor.getContent();
        if (fieldName === 'original_text' || fieldName === 'transcribed_text' || fieldName === 'notes') {
          var normalized = content
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<\/p>\s*<p>/gi, '\n\n')
            .replace(/<\/?p[^>]*>/gi, '')
            .trim();
          var div = document.createElement('div');
          div.innerHTML = normalized;
          return (div.textContent || div.innerText || '')
            .replace(/\u00a0/g, ' ')
            .trim();
        }
        return content;
      }
      var el = editForm ? editForm.querySelector('[name="' + fieldName + '"]') : null;
      return el ? el.value : '';
    }

    var hasActiveStatuses = true;
    var pollTimer = null;

    var table = $(el).DataTable({
      ajax: {
        url: pagesDataUrl,
        dataSrc: 'data',
        error: function (xhr) {
          console.error('pages-data request failed', xhr.status, xhr.responseText || xhr.statusText);
        },
      },
      columns: [
        {
          data: 'page_number',
          className: 'align-top',
          render: function (d, type, row) {
            var upBtn = row.move_up_url
              ? '<button type="button" class="btn btn-xs btn-outline-secondary js-move-up" data-url="' + escapeHtml(row.move_up_url) + '" title="Move up"><i class="icon-base bx bx-chevron-up"></i></button>'
              : '<button type="button" class="btn btn-xs btn-outline-secondary" disabled><i class="icon-base bx bx-chevron-up"></i></button>';
            var downBtn = row.move_down_url
              ? '<button type="button" class="btn btn-xs btn-outline-secondary js-move-down" data-url="' + escapeHtml(row.move_down_url) + '" title="Move down"><i class="icon-base bx bx-chevron-down"></i></button>'
              : '<button type="button" class="btn btn-xs btn-outline-secondary" disabled><i class="icon-base bx bx-chevron-down"></i></button>';
            var moveInput = '<input type="text" class="form-control form-control-sm text-center js-move-to-page" ' +
              'style="width:42px;min-width:42px;padding:0.1rem 0.2rem;" value="' + escapeHtml(d) + '" ' +
              'data-url="' + escapeHtml(row.move_to_url) + '" data-max="' + escapeHtml(row.max_page) + '" ' +
              'title="Type page number and press Enter">';
            return '<div class="d-flex align-items-start gap-2"><strong class="pt-1">' + escapeHtml(d) + '</strong><div class="d-flex flex-column gap-1 align-items-center">' + upBtn + moveInput + downBtn + '</div></div>';
          },
        },
        {
          data: null,
          orderable: false,
          className: 'align-top',
          render: function (row) {
            var u = escapeHtml(row.image_url);
            var fn = escapeHtml(row.original_filename);
            return (
              '<a href="javascript:void(0);" class="js-edit-page" title="Edit this page">' +
              '<img src="' + u + '" alt="Page ' + row.page_number + '" class="img-thumbnail" style="max-height:120px;max-width:160px;object-fit:contain" loading="lazy"/>' +
              '</a>' +
              '<div class="small text-body-secondary text-truncate mt-1" style="max-width:160px" title="' + fn + '">' + fn + '</div>'
            );
          },
        },
        {
          data: 'original_text',
          className: 'align-top',
          render: function (d) {
            return '<div class="small dt-text-col">' + escapeHtml(d) + '</div>';
          },
        },
        {
          data: 'transcribed_text',
          className: 'align-top',
          render: function (d) {
            return '<div class="small dt-text-col">' + escapeHtml(d) + '</div>';
          },
        },
        {
          data: 'notes',
          className: 'align-top',
          render: function (d) {
            return '<div class="small dt-text-col">' + escapeHtml(d) + '</div>';
          },
        },
        {
          data: 'status',
          className: 'align-top',
          render: function (d, type, row) {
            var err = row.error_short
              ? '<div class="small text-danger mt-1">' + escapeHtml(row.error_short) + '</div>'
              : '';
            var timeInfo = '';
            if (row.processing_time_ms !== null && row.processing_time_ms !== undefined) {
              var seconds = Number(row.processing_time_ms) / 1000;
              timeInfo = '<span class="status-metric-pill"><i class="icon-base bx bx-time-five me-1"></i>' + seconds.toFixed(2) + 's</span>';
            }
            var costInfo = '';
            if (row.processing_cost_usd !== null && row.processing_cost_usd !== undefined) {
              var usd = Number(row.processing_cost_usd);
              costInfo = '<span class="status-metric-pill"><i class="icon-base bx bx-dollar me-1"></i>' + usd.toFixed(6) + '</span>';
            }
            var metrics = (timeInfo || costInfo)
              ? '<div class="status-metrics mt-1">' + timeInfo + costInfo + '</div>'
              : '';
            return (
              '<span class="badge bg-label-' + escapeHtml(row.badge_class) + ' text-uppercase">' +
              escapeHtml(d) +
              '</span>' +
              metrics +
              err
            );
          },
        },
        {
          data: null,
          orderable: false,
          className: 'align-top text-end',
          render: function (row) {
            var t = csrfToken();
            return (
              '<div class="actions-stack">' +
              '<button type="button" class="btn btn-sm btn-outline-secondary js-edit-page w-100">Edit</button>' +
              '<button type="button" class="btn btn-sm btn-outline-primary js-rerun-page w-100" data-url="' + escapeHtml(row.retranscribe_url) + '">Re-run</button>' +
              '<form action="' + escapeHtml(row.destroy_url) + '" method="post" onsubmit="return confirm(\'Remove this page?\');">' +
              '<input type="hidden" name="_token" value="' + escapeHtml(t) + '">' +
              '<input type="hidden" name="_method" value="DELETE">' +
              '<button type="submit" class="btn btn-sm btn-outline-danger w-100">Delete</button></form>' +
              '</div>'
            );
          },
        },
      ],
      order: [[0, 'asc']],
      pageLength: 10,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      autoWidth: false,
      deferRender: true,
      stateSave: false,
      columnDefs: [
        { width: '80px', targets: 0 },   // #
        { width: '150px', targets: 1 },  // image
        { width: '34%', targets: 2 },    // original
        { width: '34%', targets: 3 },    // transcribed
        { width: '8%', targets: 4 },     // notes
        { width: '8%', targets: 5 },     // status
        { width: '120px', targets: 6 },  // actions
      ],
      language: {
        lengthMenu: 'Show _MENU_ pages',
        zeroRecords: 'No manuscript pages yet. Upload images above.',
        info: 'Showing _START_ to _END_ of _TOTAL_ pages',
        infoEmpty: 'No pages to show',
        infoFiltered: '(filtered from _MAX_ total pages)',
        search: 'Search:',
        paginate: {
          first: 'First',
          last: 'Last',
          next: 'Next',
          previous: 'Previous',
        },
      },
    });

    function schedulePoll() {
      if (pollTimer) clearTimeout(pollTimer);
      var delay = hasActiveStatuses ? 4000 : 20000;
      pollTimer = setTimeout(function () {
        table.ajax.reload(null, false);
      }, delay);
    }

    table.on('xhr.dt', function (e, settings, json) {
      var payload = json || settings.json;
      if (payload && Array.isArray(payload.data)) {
        hasActiveStatuses = payload.data.some(function (r) {
          return r.status === 'pending' || r.status === 'processing';
        });
      }
      schedulePoll();
    });

    $('#pages-table tbody').on('click', '.js-edit-page', function () {
      currentRow = table.row($(this).closest('tr')).data();
      if (!currentRow || !editModal || !editForm) return;

      ensureEditorsInitialized().finally(function () {
        setEditorOrTextareaValue('original_text', currentRow.original_text || '');
        setEditorOrTextareaValue('transcribed_text', currentRow.transcribed_text || '');
        setEditorOrTextareaValue('notes', currentRow.notes || '');
      });
      editForm.querySelector('[name="update_url"]').value = currentRow.update_url || '';
      if (editPreviewImg) {
        editPreviewImg.src = currentRow.image_url || '';
        editPreviewImg.alt = 'Page ' + (currentRow.page_number || '');
      }
      resetView();
      editModal.show();
    });

    $('#pages-table tbody').on('click', '.js-rerun-page', function () {
      if (!rerunModal || !rerunForm) return;
      var url = this.getAttribute('data-url') || '';
      rerunForm.setAttribute('action', url);
      rerunModal.show();
    });

    function requestMove(url) {
      if (!url) return;
      fetch(url, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken(),
          'Accept': 'application/json',
        },
      })
        .then(function (res) {
          if (!res.ok) throw new Error('Could not reorder page.');
          return res.json();
        })
        .then(function () {
          table.ajax.reload(null, false);
        })
        .catch(function (err) {
          alert(err.message || 'Could not reorder page.');
        });
    }

    $('#pages-table tbody').on('click', '.js-move-up', function () {
      requestMove(this.getAttribute('data-url') || '');
    });
    $('#pages-table tbody').on('click', '.js-move-down', function () {
      requestMove(this.getAttribute('data-url') || '');
    });
    $('#pages-table tbody').on('keydown', '.js-move-to-page', function (ev) {
      if (ev.key !== 'Enter') return;
      ev.preventDefault();

      var max = parseInt(this.getAttribute('data-max') || '1', 10);
      var val = parseInt((this.value || '').trim(), 10);
      if (!Number.isFinite(val)) return;
      if (val < 1) val = 1;
      if (val > max) val = max;
      this.value = String(val);

      var url = this.getAttribute('data-url') || '';
      if (!url) return;
      fetch(url, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken(),
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        body: new URLSearchParams({ target_page: String(val) }),
      })
        .then(function (res) {
          if (!res.ok) throw new Error('Could not move page to this position.');
          return res.json();
        })
        .then(function () {
          table.ajax.reload(null, false);
        })
        .catch(function (err) {
          alert(err.message || 'Could not move page to this position.');
        });
    });

    if (zoomInBtn) {
      zoomInBtn.addEventListener('click', function () {
        setZoom(zoomScale + 0.2);
      });
    }
    if (zoomOutBtn) {
      zoomOutBtn.addEventListener('click', function () {
        setZoom(zoomScale - 0.2);
      });
    }
    if (zoomResetBtn) {
      zoomResetBtn.addEventListener('click', function () {
        resetView();
      });
    }
    if (editPreviewImg) {
      editPreviewImg.addEventListener('wheel', function (ev) {
        ev.preventDefault();
        var delta = ev.deltaY < 0 ? 0.15 : -0.15;
        setZoom(zoomScale + delta);
      }, { passive: false });
    }
    if (editPreviewWrap) {
      editPreviewWrap.addEventListener('mousedown', function (ev) {
        if (ev.button !== 0) return;
        if (!editPreviewImg || !editPreviewImg.src) return;
        ev.preventDefault();
        dragStartX = ev.clientX;
        dragStartY = ev.clientY;
        dragOriginX = panX;
        dragOriginY = panY;
        setDragging(true);
      });
    }
    window.addEventListener('mousemove', function (ev) {
      if (!dragActive) return;
      panX = dragOriginX + (ev.clientX - dragStartX);
      panY = dragOriginY + (ev.clientY - dragStartY);
      applyZoom();
    });
    window.addEventListener('mouseup', function () {
      if (!dragActive) return;
      setDragging(false);
    });

    if (editForm) {
      editForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var formData = new FormData(editForm);
        var url = formData.get('update_url');
        if (!url) return;

        if (saveBtn) saveBtn.disabled = true;

        fetch(url, {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': csrfToken(),
            'Accept': 'application/json',
          },
          body: new URLSearchParams({
            _method: 'PATCH',
            original_text: getEditorOrTextareaValue('original_text'),
            transcribed_text: getEditorOrTextareaValue('transcribed_text'),
            notes: getEditorOrTextareaValue('notes'),
          }),
        })
          .then(function (res) {
            if (!res.ok) throw new Error('Failed to save changes.');
            return res.json();
          })
          .then(function () {
            if (editModal) editModal.hide();
            table.ajax.reload(null, false);
          })
          .catch(function (err) {
            alert(err.message || 'Could not save changes.');
          })
          .finally(function () {
            if (saveBtn) saveBtn.disabled = false;
          });
      });
    }

    window.addEventListener('beforeunload', function () {
      if (pollTimer) clearTimeout(pollTimer);
    });
  }

  function boot() {
    Promise.all([ensureDataTables(), ensureTinyMce()])
      .then(function () {
        window.jQuery(initTable);
      })
      .catch(function (err) {
        console.error(err);
        var box = document.querySelector('#pages-table');
        if (box && box.parentElement) {
          var p = document.createElement('p');
          p.className = 'text-danger small px-3';
          p.textContent = 'Could not load the pages table. See browser console.';
          box.parentElement.appendChild(p);
        }
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
<style>
  #pages-table .dt-text-col {
    white-space: pre-wrap;
    min-width: 0;
    max-width: 100%;
    overflow-wrap: anywhere;
    word-break: break-word;
  }
  #pages-table {
    table-layout: fixed;
    width: 100% !important;
  }
  .dataTables_wrapper .dataTables_length,
  .dataTables_wrapper .dataTables_filter,
  .dataTables_wrapper .dataTables_info,
  .dataTables_wrapper .dataTables_paginate {
    padding-top: 0.5rem;
    padding-bottom: 0.5rem;
  }
  .status-metrics {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    align-items: flex-start;
  }
  .status-metric-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
    font-size: 0.75rem;
    color: #6c757d;
    background: #f5f6f8;
    border: 1px solid #e5e7eb;
    border-radius: 0.375rem;
    padding: 0.1rem 0.45rem;
    line-height: 1.2;
  }
  .actions-stack {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    min-width: 96px;
  }
  .actions-stack form {
    margin: 0;
  }
  #editPageModal .modal-dialog {
    max-width: calc(100vw - 2.5rem);
    width: calc(100vw - 2.5rem);
    height: calc(100vh - 2.5rem);
    margin: 1.25rem auto;
  }
  #editPageModal .modal-content {
    height: 100%;
    border-radius: 0.5rem;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  #editPageModal #editPageForm {
    display: flex;
    flex-direction: column;
    min-height: 0;
    flex: 1 1 auto;
  }
  #editPageModal .modal-header,
  #editPageModal .modal-footer {
    flex: 0 0 auto;
    background: #fff;
    z-index: 2;
  }
  #editPageModal .modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
  }
  #editPageModal .editor-layout {
    display: grid;
    grid-template-columns: 42% 58%;
    height: 100%;
    min-height: 520px;
  }
  #editPageModal .editor-image-pane {
    border-right: 1px solid #e9ecef;
    background: #f8f9fa;
    display: flex;
    flex-direction: column;
    min-height: 0;
  }
  #editPageModal .editor-image-toolbar {
    padding: 0.75rem;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    gap: 0.5rem;
    align-items: center;
    justify-content: space-between;
  }
  #editPageModal .editor-image-wrap {
    flex: 1;
    overflow: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem;
    cursor: grab;
    user-select: none;
  }
  #editPageModal .editor-image-wrap.is-dragging {
    cursor: grabbing;
  }
  #editPageModal .editor-image-wrap img {
    max-width: 100%;
    max-height: 100%;
    transform-origin: center center;
    transition: transform 0.12s ease;
    pointer-events: none;
  }
  #editPageModal .editor-form-pane {
    min-height: 0;
  }
  @media (max-width: 991.98px) {
    #editPageModal .modal-dialog {
      max-width: calc(100vw - 1rem);
      width: calc(100vw - 1rem);
      height: calc(100vh - 1rem);
      margin: 0.5rem auto;
    }
    #editPageModal .editor-layout {
      grid-template-columns: 1fr;
    }
    #editPageModal .editor-image-pane {
      border-right: 0;
      border-bottom: 1px solid #e9ecef;
      min-height: 260px;
    }
  }
</style>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-6">
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadPagesModal">
            Upload images
        </button>
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                Generate PDF
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="{{ route('projects.pdf.full', $project) }}" target="_blank">Full PDF</a></li>
                <li><a class="dropdown-item" href="{{ route('projects.pdf.original', $project) }}" target="_blank">PDF Original</a></li>
                <li><a class="dropdown-item" href="{{ route('projects.pdf.transcribed', $project) }}" target="_blank">PDF Transcribed</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="{{ route('projects.word.full', $project) }}" target="_blank">Word (Full)</a></li>
            </ul>
        </div>
        <a href="{{ route('projects.edit', $project) }}" class="btn btn-outline-secondary btn-sm">Edit project</a>
        <a href="{{ route('projects.index') }}" class="btn btn-outline-primary btn-sm">All projects</a>
    </div>
</div>

@if (session('status'))
<div class="alert alert-success mb-4">{{ session('status') }}</div>
@endif

<div class="card mb-6">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-0">{{ $project->name }}</h5>
        </div>
    </div>
    <div class="card-body py-4">
        @if ($project->description)
        <p class="mb-0 text-body-secondary" style="white-space: pre-wrap;">{{ $project->description }}</p>
        @else
        <p class="mb-0 text-body-secondary">No project description yet.</p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">Pages</h5>
        <span class="badge bg-label-secondary">{{ $project->pages_count }} total</span>
    </div>
    <div class="card-datatable table-responsive px-3 pb-3">
        <table class="table table-striped mb-0 w-100" id="pages-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Page image</th>
                    <th>Original text</th>
                    <th>Transcribed (contemporary)</th>
                    <th>Notes</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="editPageModal" tabindex="-1" data-bs-keyboard="true" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit page texts</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="editPageForm">
        <input type="hidden" name="update_url" value="">
        <div class="modal-body p-0">
          <div class="editor-layout">
            <div class="editor-image-pane">
              <div class="editor-image-toolbar">
                <div class="btn-group btn-group-sm" role="group" aria-label="Zoom controls">
                  <button type="button" class="btn btn-outline-secondary" id="editImageZoomOutBtn">-</button>
                  <button type="button" class="btn btn-outline-secondary" id="editImageZoomInBtn">+</button>
                  <button type="button" class="btn btn-outline-secondary" id="editImageZoomResetBtn">Reset</button>
                </div>
                <span class="small text-body-secondary" id="editImageZoomLabel">100%</span>
              </div>
              <div class="editor-image-wrap">
                <img id="editPagePreviewImage" src="" alt="Page preview">
              </div>
            </div>
            <div class="editor-form-pane p-3">
              <div class="mb-3">
                <label class="form-label fs-5 fw-semibold">Original text</label>
                <textarea class="form-control" id="edit-original-text" name="original_text" rows="6"></textarea>
              </div>
              <div class="mt-4 mb-3">
                <label class="form-label fs-5 fw-semibold">Transcribed (contemporary)</label>
                <textarea class="form-control" id="edit-transcribed-text" name="transcribed_text" rows="6"></textarea>
              </div>
              <div class="mt-4 mb-0">
                <label class="form-label fs-5 fw-semibold">Notes</label>
                <textarea class="form-control" id="edit-notes" name="notes" rows="4" placeholder="Page notes."></textarea>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" id="editPageSaveBtn" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="uploadPagesModal" tabindex="-1" data-bs-keyboard="true" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Batch upload</h5>
          <small class="text-body-secondary">Files are ordered alphabetically by original filename to assign page numbers.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="{{ route('projects.pages.store', $project) }}" method="post" enctype="multipart/form-data">
        @csrf
        <div class="modal-body">
          <p class="text-body-secondary mb-3">Use this form to upload one or multiple page images.</p>
          <div class="mb-3">
            <label class="form-label" for="files-modal">Page images</label>
            <input class="form-control @error('files') is-invalid @enderror @error('files.*') is-invalid @enderror" type="file" id="files-modal" name="files[]" accept="image/*,.tif,.tiff" multiple required>
            @error('files')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            @error('files.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            <div class="form-text">Supported: JPG, PNG, WEBP, GIF, TIFF (max 25 MB per file).</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Upload &amp; queue transcription</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="rerunConfirmModal" tabindex="-1" data-bs-keyboard="true" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm re-run</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">This will queue transcription again for this page.</p>
        <p class="mb-0 text-danger">
          <strong>Warning:</strong> this action will overwrite the current
          <strong>Original text</strong> and <strong>Transcribed text</strong>.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <form id="rerunConfirmForm" method="post" action="">
          @csrf
          <button type="submit" class="btn btn-primary">Yes, re-run transcription</button>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection
