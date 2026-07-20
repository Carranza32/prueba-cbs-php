import * as React  from "react";
import { useEffect } from 'react';
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { TextControl, Panel, PanelBody, PanelRow } from "@wordpress/components";
import ServerSideRender from "@wordpress/server-side-render";

import metadata from "./block.json";

import "./editor.css";
import "./view.css";
import close from './assets/close-icon.svg';

function Edit(props) {
    const blockBlocks = useBlockProps();

    useEffect(() => {
        console.log('close', close);
        props.setAttributes({
            closeicon: close,
        });
    }, [props]);

    return (
        <div {...blockBlocks}>
            <InspectorControls>
                <Panel>
                    <PanelBody title="Settings" icon="more" initialOpen={true}>
                        <PanelRow>
                            <TextControl
                                value={props.attributes.shortcodes}
                                onChange={(value) => {
                                    props.setAttributes({
                                        shortcodes: value,
                                    });
                                }}
                                label="Upsell Custom Shortcode"
                                placeholer="the shortcode here..."
                            />
                        </PanelRow>
                    </PanelBody>
                </Panel>
            </InspectorControls>
            <ServerSideRender
                block={metadata.name}
                attributes={props.attributes}
            />
        </div>
    );
}

registerBlockType(metadata.name, {
    attributes: metadata.attributes,
    title: metadata.title,
    category: metadata.category,
    edit: Edit,
    save() {
        return null;
    },
});
