(function (wp) {
    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var TextControl = wp.components.TextControl;
    var registerBlockType = wp.blocks.registerBlockType;

    registerBlockType('kiyoh/shop-reviews', {
        edit: function (props) {
            var limit = props.attributes.limit;
            return el(
                'div',
                { className: 'kiyoh-shop-reviews-block-editor' },
                el(
                    'p',
                    null,
                    __('Kiyoh shop reviews', 'kiyoh-woocommerce')
                ),
                el(
                    InspectorControls,
                    null,
                    el(TextControl, {
                        label: __('Number of reviews', 'kiyoh-woocommerce'),
                        type: 'number',
                        min: 1,
                        max: 50,
                        value: limit,
                        onChange: function (value) {
                            props.setAttributes({ limit: parseInt(value, 10) || 5 });
                        },
                    })
                ),
                el(
                    'p',
                    { className: 'description' },
                    __('Preview appears on the storefront when shop reviews are enabled in WooCommerce → Kiyoh.', 'kiyoh-woocommerce')
                )
            );
        },
        save: function () {
            return null;
        },
    });
})(window.wp);