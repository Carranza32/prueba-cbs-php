import * as React from "react";
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { TextControl, Panel, PanelBody, PanelRow } from "@wordpress/components";
import ServerSideRender from "@wordpress/server-side-render";
import placeholder from './assets/placeholder.png';
import loader from './assets/loader-icon.gif';
import { useEffect } from 'react';

import metadata from "./block.json";

import "./editor.css";
import "./view.css";

function Edit(props) {
    const blockBlocks = useBlockProps();

    useEffect(() => {
        props.setAttributes({
            defaultimage: placeholder,
            loader: loader,
        });
    }, [props]);


    return (
        <div {...blockBlocks}>
            <InspectorControls>
                <Panel>
                    <PanelBody title="Settings" icon="more" initialOpen={true}>
                        <PanelRow>
                            <TextControl
                                value={props.attributes.button}
                                onChange={(value) => {
                                    props.setAttributes({
                                        button: value,
                                    });
                                }}
                                label="Placer Order Button Text"
                                placeholer="add the text here"
                            />
                        </PanelRow>
                        <PanelRow>
                            <TextControl
                                value={props.attributes.slug}
                                onChange={(value) => {
                                    props.setAttributes({
                                        slug: value,
                                    });
                                }}
                                label="Redirect Slug"
                                placeholer="add the Slug here"
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
