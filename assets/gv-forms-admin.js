/*
 * GV Forms — visual field builder (PATCHED v2.3)
 * Admin JS (iframe-only preview)
 *
 * Adds: background opacity slider + preview-only backdrop select.
 * Preserves the original preview height logic and form positioning.
 */
(function ($) {
  // ---------- helpers ----------
  const esc = (s) =>
    String(s ?? "").replace(/[&<>"']/g, (m) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[m])
    );

  const slugify = (s) =>
    String(s || "")
      .toLowerCase()
      .trim()
      .replace(/[^\w\s-]/g, '')
      .replace(/[\s_-]+/g, '_')
      .replace(/^_+|_+$/g, '');

  let dirty = false;
  const markDirty = () => (dirty = true);

  let previewTimeout;
  let previewXHR = null;

  function triggerPreview(){
    markDirty();
    clearTimeout(previewTimeout);
    previewTimeout = setTimeout(requestPreview, 300);
  }

  // ---------- color pickers + title ----------
  const pickerOptions = { change: triggerPreview };

  $("#gv-label-color").wpColorPicker(pickerOptions);
  $("#gv-bg-color").wpColorPicker(pickerOptions);
  $("#gv-border-color").wpColorPicker(pickerOptions);
  $("#gv-title-color").wpColorPicker(pickerOptions);

  // NEW: button color picker
  $("#gv-btn-color").wpColorPicker(pickerOptions);

  const $titleText  = $("#gv-title-text");
  const $titleAlign = $("#gv-title-align");

  if (window.gvFormsAdmin && gvFormsAdmin.title) {
    if (gvFormsAdmin.title.text != null) $titleText.val(gvFormsAdmin.title.text);
    if (gvFormsAdmin.title.align) $titleAlign.val(gvFormsAdmin.title.align);
    if (gvFormsAdmin.title.color) $("#gv-title-color").val(gvFormsAdmin.title.color).trigger("change");
  }

  $(".gv-form-title-controls .gv-align").removeClass("button-primary");
  $('.gv-form-title-controls .gv-align[data-align="' + ($titleAlign.val() || "left") + '"]').addClass("button-primary");

  $(".gv-form-title-controls").on("click", ".gv-align", function () {
    const a = $(this).data("align");
    $titleAlign.val(a);
    $(this).addClass("button-primary").siblings(".gv-align").removeClass("button-primary");
    triggerPreview();
  });

  // ---------- presets toggles (enable/disable solid pickers) ----------
  const $bgPreset  = $("#gv-bg-preset");
  const $btnPreset = $("#gv-btn-preset");
  const $bgColor   = $("#gv-bg-color");
  const $btnColor  = $("#gv-btn-color");

  function syncSolidDisablers(){
    $bgColor.prop("disabled",  ($bgPreset.val()  || "none") !== "none");
    $btnColor.prop("disabled", ($btnPreset.val() || "none") !== "none");
  }
  $bgPreset.on("change", () => { syncSolidDisablers(); triggerPreview(); });
  $btnPreset.on("change", () => { syncSolidDisablers(); triggerPreview(); });
  syncSolidDisablers();

  // ---------- field list ----------
  const $list = $("#gv-field-list");
  const defaultField = () => ({ label:"New field", slug:"new_field", type:"text", required:0, placeholder:"" });

  const rowTpl = (f) => `
    <div class="gv-field">
      <span class="dashicons dashicons-menu drag-handle"></span>
      <input class="label" placeholder="Label" value="${esc(f.label)}">
      <input class="slug"  placeholder="slug"  value="${esc(f.slug)}">
      <select class="type">
        <option value="text"     ${f.type==="text"?"selected":""}>TEXT</option>
        <option value="email"    ${f.type==="email"?"selected":""}>EMAIL</option>
        <option value="textarea" ${f.type==="textarea"?"selected":""}>TEXTAREA</option>
      </select>
      <input class="placeholder" placeholder="Placeholder" value="${esc(f.placeholder ?? "")}">
      <label style="white-space:nowrap"><input type="checkbox" class="req" ${f.required?"checked":""}> required</label>
      <button class="remove" title="Delete">×</button>
    </div>`;

  const emptyStateTpl = () => `
    <div class="gv-empty-state" style="text-align:center; padding:3rem 2rem; color:var(--gv-muted); background:var(--gv-surface-2); border-radius:12px; border:2px dashed var(--gv-border);">
      <span class="dashicons dashicons-feedback" style="font-size:48px; opacity:0.5; margin-bottom:1rem;"></span>
      <p style="font-size:1.1rem; margin:0 0 0.5rem; color:var(--gv-text);">No fields yet</p>
      <p style="font-size:0.9rem; margin:0;">Click "Add field" below to create your first form field</p>
    </div>`;

  let fields = gvFormsAdmin.fields || [];

  const renderList = () => {
    if (fields.length === 0) {
      $list.html(emptyStateTpl());
      return;
    }
    $list.html(fields.map(rowTpl).join(""));
  };
  renderList();

  $list.sortable({
    handle: ".drag-handle",
    placeholder: "gv-field-placeholder",
    containment: "parent",
    axis: "y",
    tolerance: "pointer",
    start(e, ui){
      ui.placeholder.height(ui.helper.outerHeight());
      ui.helper.css('opacity', '0.8');
    },
    stop(e, ui) { ui.item.css('opacity', '1'); },
    update(){ triggerPreview(); }
  });

  $("#gv-add").on("click", () => {
    fields.push(defaultField());
    renderList();
    triggerPreview();
    setTimeout(() => {
      const $newField = $list.children().last();
      $newField.find('.label').focus();
    }, 100);
  });

  $list.on("click", ".remove", function () {
    if (confirm('Delete this field?')) {
      $(this).closest(".gv-field").fadeOut(200, function() {
        $(this).remove();
        triggerPreview();
      });
    }
  });

  // auto-slug
  $list.on("input", ".label", function () {
    const $row = $(this).closest(".gv-field");
    const $slug = $row.find(".slug");
    if (!$slug.data("touched") && !$slug.val().trim()) {
      $slug.val(slugify($(this).val()));
    }
  });

  $list.on("input", ".slug", function () {
    $(this).data("touched", true);
    const clean = slugify($(this).val());
    if ($(this).val() !== clean) {
      const pos = this.selectionStart;
      $(this).val(clean);
      this.selectionStart = this.selectionEnd = pos;
    }
  });

  const collect = () => $list.children(".gv-field").map(function () {
    const el = $(this);
    let label = el.find(".label").val().trim();
    let slug  = el.find(".slug").val().trim();
    let type  = el.find(".type").val();
    const req = el.find(".req").is(":checked") ? 1 : 0;
    const ph  = el.find(".placeholder").val().trim();

    if (!label) label = "Untitled Field";
    if (!slug) slug = slugify(label);
    slug = slugify(slug || label || "field");
    if (slug === "email")   type = "email";
    if (slug === "message") type = "textarea";

    return { label, slug, type, required:req, placeholder:ph };
  }).get();

  const dedupeSlugs = (arr) => {
    const used = Object.create(null);
    arr.forEach((f) => {
      let base = f.slug || "field", s = base, i = 1;
      while (used[s]) s = `${base}_${++i}`;
      used[s] = 1; f.slug = s;
    });
    return arr;
  };

  function readFields(){ return dedupeSlugs(collect()); }

  const validateFields = (fields) => {
    const errors = [];
    const slugs = new Set();
    fields.forEach((f, i) => {
      if (!f.label.trim()) errors.push(`Field ${i + 1}: Label is required`);
      if (!f.slug.trim())  errors.push(`Field ${i + 1}: Slug is required`);
      if (slugs.has(f.slug)) errors.push(`Duplicate slug detected: ${f.slug}`);
      slugs.add(f.slug);
    });
    return errors;
  };

  // ---------- save ----------
  const $save = $("#gv-save"), $msg = $("#gv-save-msg");

  $("#gv-save").on("click", () => {
    fields = readFields();
    const errors = validateFields(fields);
    if (errors.length > 0) {
      $msg.html('<span style="color:#f87171">⚠ ' + errors[0] + '</span>');
      setTimeout(() => $msg.html(''), 3000);
      return;
    }

    const payload = {
      action:"gv_save_fields",
      nonce: gvFormsAdmin.nonce,
      fields: JSON.stringify(fields),
      // Global Styles
      label_color: $("#gv-label-color").val(),
      bg_color:     $bgColor.val(),
      glass_blur:   $("#gv-glass-blur").val(),
      bg_opacity:   $("#gv-bg-opacity").val(),   // NEW
      padding:      $("#gv-padding").val(),
      border_radius:$("#gv-border-radius").val(),
      border_width: $("#gv-border-width").val(),
      border_style: $("#gv-border-style").val(),
      border_color: $("#gv-border-color").val(),
      shadow:       $("#gv-shadow-toggle").is(":checked") ? "1" : "0",
      // Presets + button
      bg_preset:    $bgPreset.val(),
      btn_preset:   $btnPreset.val(),
      btn_color:    $btnColor.val(),
      // Title
      title_text :  $("#gv-title-text").val().trim(),
      title_align:  $("#gv-title-align").val(),
      title_color:  $("#gv-title-color").val(),
      // Localization
      consent_text: $("#gv-consent-text").val(),
      submit_text:  $("#gv-submit-text").val()
    };

    $(".spinner").addClass("is-active");
    $save.addClass("saving").prop("disabled", true).text("Saving...");
    $msg.text("");

    $.post(gvFormsAdmin.ajaxUrl, payload)
      .done(() => {
        $msg.html('<span style="color:#22c55e">✓ Saved successfully</span>');
        setTimeout(() => $msg.text(""), 2000);
        dirty = false;
      })
      .fail((xhr) => {
        const errorMsg = xhr?.responseJSON?.data || xhr?.responseText || "Error saving";
        $msg.html('<span style="color:#f87171">✗ ' + esc(errorMsg) + '</span>');
      })
      .always(() => {
        $(".spinner").removeClass("is-active");
        $save.removeClass("saving").prop("disabled", false).text("Save fields");
      });
  });

  // ---------- unsaved guard ----------
  window.addEventListener("beforeunload", (e) => {
    if (dirty) {
      e.preventDefault();
      e.returnValue = "You have unsaved changes. Are you sure you want to leave?";
    }
  });

  // blur + opacity value display
  $(function(){
    const $blur = $("#gv-glass-blur"), $val = $("#gv-glass-blur-val");
    $blur.on("input", function(){ $val.text($(this).val()+"px"); });

    const $op = $("#gv-bg-opacity"), $opVal = $("#gv-bg-opacity-val");
    if ($op.length && $opVal.length) {
      $op.on("input change", function(){ $opVal.text($(this).val()+"%"); });
    }
  });

  // ===== iframe live preview only =====
  const $frame = $("#gv-live-frame");
  const $backdrop = $("#gv-preview-backdrop"); // preview-only select

  // Replace your previewBackdropCSS with this
function previewBackdropCSS(mode){
  switch (mode) {
    case "gradient":
      return [
        "background:linear-gradient(135deg,#0ea5e9 0%,#22d3ee 40%,#14b8a6 100%);",
        "background-attachment:fixed;"
      ].join("");

    case "grid":
      // dark base + crisp grid + soft radial accents
      return [
        "background-color:#0b1220;",
        "background-image:",
          "linear-gradient(rgba(255,255,255,.06) 1px,transparent 1px),",
          "linear-gradient(90deg,rgba(255,255,255,.06) 1px,transparent 1px),",
          "radial-gradient(circle at 0 0, rgba(255,255,255,.05) 0 8%, transparent 9%),",
          "radial-gradient(circle at 100% 100%, rgba(255,255,255,.04) 0 10%, transparent 11%);",
        "background-size: 40px 40px, 40px 40px, 140px 140px, 160px 160px;",
        "background-position: 0 0, 0 0, 0 0, 0 0;",
        "background-repeat: repeat, repeat, no-repeat, no-repeat;",
        "background-attachment: fixed, fixed, fixed, fixed;"
      ].join("");

    case "scene":
      // tiled SVG UI cards + two large radial glows
      return [
        "background-color:#0b1220;",
        "background-image:",
          "radial-gradient(1200px 600px at 80% -20%, rgba(255,255,255,.08), transparent 60%),",
          "radial-gradient(800px 400px at -10% 120%, rgba(255,255,255,.05), transparent 60%),",
          "url(\"data:image/svg+xml;utf8,",
            "<svg xmlns='http://www.w3.org/2000/svg' width='240' height='160' viewBox='0 0 240 160'>",
              "<rect x='16' y='20' width='200' height='120' rx='16' fill='%23ffffff' fill-opacity='.04' stroke='%23ffffff' stroke-opacity='.06'/>",
              "<rect x='36' y='36' width='80' height='12' rx='6' fill='%23ffffff' fill-opacity='.08'/>",
              "<rect x='36' y='60' width='160' height='10' rx='5' fill='%23ffffff' fill-opacity='.06'/>",
              "<rect x='36' y='84' width='140' height='10' rx='5' fill='%23ffffff' fill-opacity='.06'/>",
            "</svg>\");",
        "background-repeat: no-repeat, no-repeat, repeat;",
        "background-size: auto, auto, 240px 160px;",
        "background-position: center, center, 0 0;",
        "background-attachment: fixed, fixed, fixed;"
      ].join("");

    case "site":
      return "background:#000;";

    default:
      return "background:#000;";
  }
}


  function payload(){
    return {
      fields: JSON.stringify(readFields()),
      // Global Styles
      label_color: $("#gv-label-color").val(),
      bg_color:     $bgColor.val(),
      glass_blur:   $("#gv-glass-blur").val(),
      bg_opacity:   $("#gv-bg-opacity").val(),   // NEW
      padding:      $("#gv-padding").val(),
      border_radius:$("#gv-border-radius").val(),
      border_width: $("#gv-border-width").val(),
      border_style: $("#gv-border-style").val(),
      border_color: $("#gv-border-color").val(),
      shadow:       $("#gv-shadow-toggle").is(":checked") ? "1" : "0",
      // Presets + button
      bg_preset:    $bgPreset.val(),
      btn_preset:   $btnPreset.val(),
      btn_color:    $btnColor.val(),
      // Title
      title_text:   $("#gv-title-text").val(),
      title_color:  $("#gv-title-color").val(),
      title_align:  $("#gv-title-align").val(),
      // Localization
      consent_text: $("#gv-consent-text").val(),
      submit_text:  $("#gv-submit-text").val(),
      // Preview-only
      _backdrop: ($backdrop.val() || "grid")
    };
  }

  function paintIframe(html){
    if(!$frame.length) return;
    const css = gvFormsAdmin.previewCss || "";
    const backdrop = previewBackdropCSS($backdrop.val() || "grid");
    const srcdoc = `<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>html,body{margin:0;padding:16px;${backdrop}}</style>
<style>${css}</style></head><body>${html}
<script>
try{
  const ro=new ResizeObserver(()=>parent.postMessage({type:'gvPreviewHeight',h:document.body.scrollHeight},'*'));
  ro.observe(document.body);
  window.addEventListener('load',()=>parent.postMessage({type:'gvPreviewHeight',h:document.body.scrollHeight},'*'));
}catch(e){ parent.postMessage({type:'gvPreviewHeight',h:document.body.scrollHeight},'*'); }
<\/script></body></html>`;
    $frame.get(0).srcdoc = srcdoc;
  }

  function requestPreview(){
    if (previewXHR && previewXHR.abort) previewXHR.abort();

    previewXHR = $.post(
      gvFormsAdmin.ajaxUrl,
      { action: "gv_save_fields", nonce: gvFormsAdmin.nonce, preview_only: 1, ...payload() },
      function(res) {
        if (res?.success && res.data?.html) paintIframe(res.data.html);
        previewXHR = null;
      }
    ).fail(function() { previewXHR = null; });
  }

  // adjust iframe height
  window.addEventListener("message", (ev) => {
    if (!ev?.data || ev.data.type !== "gvPreviewHeight") return;
    const h = Math.max(200, Math.min(2400, parseInt(ev.data.h,10) || 0));
    $frame.height(h);
  });

  // wire changes → preview
  $(document).on("input change",
    "#gv-glass-blur," +
    " #gv-title-text, #gv-title-align," +
    " #gv-field-list input, #gv-field-list select," +
    " #gv-padding, #gv-border-radius, #gv-shadow-toggle," +
    " #gv-border-width, #gv-border-style, #gv-border-color," +
    " #gv-consent-text, #gv-submit-text," +
    " #gv-bg-color, #gv-label-color, #gv-title-color," +
    " #gv-btn-color, #gv-bg-preset, #gv-btn-preset," +
    " #gv-bg-opacity, #gv-preview-backdrop",
    triggerPreview
  );

  // initial paint
  $(triggerPreview);
})(jQuery);
