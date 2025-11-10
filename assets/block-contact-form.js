(function (blocks, element, i18n, components, blockEditor, ServerSideRender) {
  const el = element.createElement;

  blocks.registerBlockType('gardevault/contact-form', {
    title: 'GV Contact Form',
    description: 'Embed the GV form configured in GV Forms → Builder.',
    icon: 'feedback',
    category: 'widgets',
    supports: { html: false },

    edit() {
      return el('div', { className: 'gv-block-wrap' },
        el(ServerSideRender, { block: 'gardevault/contact-form' }),
        el('p', { className: 'description' }, '[gv_contact_form] also works anywhere.')
      );
    },

    save() { return null; } // dynamic PHP render
  });

})(
  window.wp.blocks,
  window.wp.element,
  window.wp.i18n,
  window.wp.components,
  window.wp.blockEditor,
  window.wp.serverSideRender
);
