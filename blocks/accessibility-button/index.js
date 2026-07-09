/**
 * Accessibility Button — Block Editor Script
 *
 * Registers the block type and provides the editor preview component.
 * Uses WordPress globals — no build step required.
 */
(function (wp) {
  "use strict";

  var registerBlockType = wp.blocks.registerBlockType;
  var el = wp.element.createElement;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var PanelBody = wp.components.PanelBody;
  var TextControl = wp.components.TextControl;
  var SelectControl = wp.components.SelectControl;
  var RangeControl = wp.components.RangeControl;
  var ColorPalette = wp.components.ColorPalette;
  var __ = wp.i18n.__;

  /**
   * SVG icon paths keyed by icon name.
   */
  var iconPaths = {
    accessibility: [
      el("circle", { cx: 50, cy: 22, r: 10 }),
      el("path", {
        d: "M15 35 L50 45 L85 35 M50 45 L50 65 M30 90 L50 65 L70 90",
        fill: "none",
        stroke: "currentColor", // or 'white' depending on your CSS
        strokeWidth: 12,
        strokeLinecap: "round",
        strokeLinejoin: "round",
      }),
    ]
  };

  /**
   * Build an SVG icon element.
   *
   * @param {string} name Icon key.
   * @param {number|string} size Width/height.
   * @return {Object|null} createElement VNode, or null if 'none'.
   */
  function buildIcon(name, size) {
    var paths = iconPaths[name];
    if (!paths) {
      return null;
    }

    var children = paths.map(function (child) {
      return el(
        child.type,
        Object.assign({}, child.props, {
          fill: child.props.fill || "currentColor",
        }),
      );
    });

    return el(
      "svg",
      {
        xmlns: "http://www.w3.org/2000/svg",
        viewBox: "0 0 100 100",
        width: size,
        height: size,
      },
      children,
    );
  }

  /**
   * Size presets for the preview.
   */
  var sizePresets = {
    small: { iconOnly: 40, font: 13, iconSize: 20 },
    medium: { iconOnly: 48, font: 14, iconSize: 24 },
    large: { iconOnly: 56, font: 16, iconSize: 28 },
  };

  registerBlockType("apppresser/accessibility-button", {
    edit: function (props) {
      var attributes = props.attributes;
      var setAttributes = props.setAttributes;
      var hasLabel = attributes.label && attributes.label.trim().length > 0;
      var iconName = attributes.icon || "accessibility";
      var sizeKey = attributes.size || "medium";
      var size = sizePresets[sizeKey] || sizePresets.medium;
      var customPadding =
        attributes.padding !== undefined ? attributes.padding : 8;
      var bgColor = attributes.backgroundColor || "#005a9c";
      var pad = customPadding;
      var iconEl =
        iconName !== "none"
          ? buildIcon(iconName, hasLabel ? size.iconSize : "100%")
          : null;

      return el(
        "div",
        {},
        el(
          InspectorControls,
          {},
          el(
            PanelBody,
            {
              title: __("Button Settings", "apppresser-wp"),
              initialOpen: true,
            },
            el(TextControl, {
              label: __("Button Label", "apppresser-wp"),
              value: attributes.label,
              onChange: function (value) {
                setAttributes({ label: value });
              },
            }),
            el(SelectControl, {
              label: __("Icon", "apppresser-wp"),
              value: iconName,
              options: [
                {
                  label: __("Accessibility", "apppresser-wp"),
                  value: "accessibility",
                },
                { label: __("Eye", "apppresser-wp"), value: "eye" },
                { label: __("Contrast", "apppresser-wp"), value: "contrast" },
                { label: __("Text", "apppresser-wp"), value: "text" },
                { label: __("Person", "apppresser-wp"), value: "person" },
                { label: __("None", "apppresser-wp"), value: "none" },
              ],
              onChange: function (value) {
                setAttributes({ icon: value });
              },
            }),
            el(SelectControl, {
              label: __("Size", "apppresser-wp"),
              value: sizeKey,
              options: [
                { label: __("Small", "apppresser-wp"), value: "small" },
                { label: __("Medium", "apppresser-wp"), value: "medium" },
                { label: __("Large", "apppresser-wp"), value: "large" },
              ],
              onChange: function (value) {
                setAttributes({ size: value });
              },
            }),
            el(SelectControl, {
              label: __("Placement", "apppresser-wp"),
              value: attributes.placement,
              options: [
                {
                  label: __("Bottom Left", "apppresser-wp"),
                  value: "left_bottom",
                },
                {
                  label: __("Bottom Right", "apppresser-wp"),
                  value: "right_bottom",
                },
                { label: __("Top Left", "apppresser-wp"), value: "left_top" },
                { label: __("Top Right", "apppresser-wp"), value: "right_top" },
              ],
              onChange: function (value) {
                setAttributes({ placement: value });
              },
            }),
          ),
          el(
            PanelBody,
            { title: __("Style", "apppresser-wp"), initialOpen: false },
            el(RangeControl, {
              label: __("Padding", "apppresser-wp"),
              value: customPadding,
              onChange: function (value) {
                setAttributes({ padding: value });
              },
              min: 0,
              max: 40,
              allowReset: true,
              resetFallbackValue: 0,
            }),
            el(
              "div",
              { style: { marginBottom: "16px" } },
              el(
                "label",
                {
                  style: {
                    display: "block",
                    marginBottom: "8px",
                    fontSize: "11px",
                    fontWeight: 500,
                    textTransform: "uppercase",
                  },
                },
                __("Background Color", "apppresser-wp"),
              ),
              el(ColorPalette, {
                value: attributes.backgroundColor || "",
                onChange: function (value) {
                  setAttributes({ backgroundColor: value || "" });
                },
                clearable: true,
                colors: [
                  { name: __("Blue", "apppresser-wp"), color: "#005a9c" },
                  { name: __("Dark", "apppresser-wp"), color: "#1a1a1a" },
                  { name: __("White", "apppresser-wp"), color: "#ffffff" },
                  { name: __("Red", "apppresser-wp"), color: "#c02b2b" },
                  { name: __("Green", "apppresser-wp"), color: "#2b7a3e" },
                  { name: __("Purple", "apppresser-wp"), color: "#6b3fa0" },
                ],
              }),
            ),
          ),
        ),
        el(
          "div",
          {
            className:
              "appp-a11y-block-preview" +
              (hasLabel ? "" : " appp-a11y-block-preview--round"),
            style: {
              display: "inline-flex",
              alignItems: "center",
              justifyContent: "center",
              gap: hasLabel ? "8px" : "0",
              padding: hasLabel ? pad + "px " + pad + "px" : pad + "px",
              width: hasLabel ? "auto" : size.iconOnly + "px",
              height: hasLabel ? "auto" : size.iconOnly + "px",
              background: bgColor,
              color: "#fff",
              borderRadius: hasLabel ? "50px" : "50%",
              fontFamily:
                '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
              fontSize: size.font + "px",
              fontWeight: 600,
              cursor: "default",
            },
          },
          iconEl
            ? el(
                "span",
                {
                  style: {
                    display: "inline-flex",
                    alignItems: "center",
                    justifyContent: "center",
                    width: hasLabel ? size.iconSize + "px" : "100%",
                    height: hasLabel ? size.iconSize + "px" : "100%",
                  },
                },
                iconEl,
              )
            : null,
          hasLabel ? attributes.label : null,
        ),
      );
    },
    save: function () {
      // Dynamic block — rendered server-side via render.php.
      return null;
    },
  });
})(window.wp);
