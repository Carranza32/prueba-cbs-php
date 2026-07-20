import * as React from "react";
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { TextControl, Panel, PanelBody, PanelRow } from "@wordpress/components";
import ServerSideRender from "@wordpress/server-side-render";

import metadata from "./block.json";

import "./editor.css";
import "./view.css";

function Edit(props) {
    const blockBlocks = useBlockProps();

    return (
        <div {...blockBlocks}>
            <InspectorControls>
                <Panel>
                    <PanelBody title="Settings" icon="more" initialOpen={true}>
                        <PanelRow>
                            <TextControl
                                value={props.attributes.publicToken}
                                onChange={(value) => {
                                    props.setAttributes({
                                        publicToken: value,
                                    });
                                }}
                                label="Findkit Public Token"
                                placeholer="The token here..."
                                help="Get public token from the Findkit Hub"
                            />
                        </PanelRow>
                        <PanelRow>
                            <TextControl
                                value={props.attributes.instanceId}
                                onChange={(value) => {
                                    props.setAttributes({
                                        instanceId: value,
                                    });
                                }}
                                label="FindkitUI Instance ID"
                                placeholer="The id here..."
                                help={
                                    <>
                                        Must be unique for each block on a page.
                                        See the{" "}
                                        <a href="https://findk.it/instanceid">
                                            docs
                                        </a>
                                        .
                                    </>
                                }
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
