/*
 * GV Forms — visual field builder
 * Admin JS (iframe-only preview)
 */
(function ($) {
  // ---------- helpers ----------
  const esc = (s) =>
    String(s ?? "").replace(/[&<>"']/g, (m) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[m])
    );
  const slugify = (s) =>
    String(s || "").toLowerCase().trim().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");

  let dirty = false;
  const markDirty = () => (dirty = true);

  // ---------- color pickers + title ----------
  const $labelColor = $("#gv-label-color").wpColorPicker();
  const $titleText  = $("#gv-title-text");
  const $titleAlign = $("#gv-title-align");
  $("#gv-title-color").wpColorPicker();

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
    markDirty();
    triggerPreview();
  });

  $("#gv-title-text, #gv-title-color, #gv-label-color").on("change input", function(){
    markDirty(); triggerPreview();
  });

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

  let fields = gvFormsAdmin.fields || [];
  const renderList = () => $list.html(fields.map(rowTpl).join(""));
  renderList();

  $list.on("input change", "input, select", function(){ markDirty(); triggerPreview(); });

  $list.sortable({
    handle: ".drag-handle",
    placeholder: "gv-field-placeholder",
    start(e, ui){ ui.placeholder.height(ui.helper.outerHeight()); },
    update(){ markDirty(); triggerPreview(); }
  });

  $("#gv-add").on("click", () => { fields.push(defaultField()); renderList(); markDirty(); triggerPreview(); });

  $list.on("click", ".remove", function () { $(this).closest(".gv-field").remove(); markDirty(); triggerPreview(); });

  // auto-slug
  $list.on("input", ".label", function () {
    const $row = $(this).closest(".gv-field");
    const $slug = $row.find(".slug");
    if (!$slug.data("touched") && !$slug.val().trim()) $slug.val(slugify($(this).val()));
  });
  $list.on("input", ".slug", function () { $(this).data("touched", true); });

  // collect from DOM
  const collect = () => $list.children().map(function () {
    const el = $(this);
    let label = el.find(".label").val().trim();
    let slug  = el.find(".slug").val().trim();
    let type  = el.find(".type").val();
    const req = el.find(".req").is(":checked") ? 1 : 0;
    const ph  = el.find(".placeholder").val().trim();

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

  // ---------- save ----------
  const $save = $("#gv-save"), $msg = $("#gv-save-msg");
  $("#gv-save").on("click", () => {
    fields = readFields();
    const payload = {
      action:"gv_save_fields",
      nonce: gvFormsAdmin.nonce,
      fields: JSON.stringify(fields),
      label_color: $labelColor.val(),
      title_text : $titleText.val().trim(),
      title_align: $titleAlign.val(),
      title_color: $("#gv-title-color").val()
    };
    $(".spinner").addClass("is-active"); $save.prop("disabled", true); $msg.text("");
    $.post(gvFormsAdmin.ajaxUrl, payload)
      .done(() => { $msg.text("Saved"); setTimeout(() => $msg.text(""), 1500); dirty = false; })
      .fail((xhr) => { $msg.text(xhr?.responseText || "Error"); })
      .always(() => { $(".spinner").removeClass("is-active"); $save.prop("disabled", false); });
  });

  // ---------- unsaved guard ----------
  window.addEventListener("beforeunload", (e) => { if (dirty){ e.preventDefault(); e.returnValue = ""; } });

  // blur value display
  $(function(){
    const $blur = $("#gv-glass-blur"), $val = $("#gv-glass-blur-val");
    $blur.on("input", function(){ $val.text($(this).val()+"px"); });
  });

  // ===== iframe live preview only =====
  const $frame = $("#gv-live-frame");

  function payload(){
    return {
      fields: JSON.stringify(readFields()),
      label_color: $("#gv-label-color").val(),
      bg_color:    $("#gv-bg-color").val(),
      glass_blur:  $("#gv-glass-blur").val(),
      border:      $("#gv-border-toggle").is(":checked") ? "1" : "0",
      title_text:  $("#gv-title-text").val(),
      title_color: $("#gv-title-color").val(),
      title_align: $("#gv-title-align").val()
    };
  }

  function paintIframe(html){
    if(!$frame.length) return;
    const css = gvFormsAdmin.previewCss || ""; // must be localized from PHP
    const srcdoc = `<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>html,body{margin:0;padding:16px;background:#000;color:#e5e7eb}</style>
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
    $.post(gvFormsAdmin.ajaxUrl, { action:"gv_save_fields", nonce:gvFormsAdmin.nonce, preview_only:1, ...payload() },
      function(res){ if(res?.success && res.data?.html) paintIframe(res.data.html); });
  }

  function triggerPreview(){ requestPreview(); }

  // adjust iframe height based on child message
  window.addEventListener("message", (ev) => {
    if (!ev?.data || ev.data.type !== "gvPreviewHeight") return;
    const h = Math.max(200, Math.min(2400, parseInt(ev.data.h,10) || 0));
    $frame.height(h);
  });

  // wire changes → preview
  $(document).on("input change",
    "#gv-label-color, #gv-bg-color, #gv-glass-blur, #gv-border-toggle," +
    " #gv-title-text, #gv-title-color, #gv-title-align," +
    " #gv-field-list input, #gv-field-list select",
    triggerPreview
  );

  // initial paint
  $(triggerPreview);
})(jQuery);
