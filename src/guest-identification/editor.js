import * as React from "react";
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { TextControl, ToggleControl, Panel, PanelBody, PanelRow } from "@wordpress/components";
import ServerSideRender from "@wordpress/server-side-render";

import metadata from "./block.json";

import "./editor.css";
import "./view.css";

function Edit({attributes, setAttributes}) {
    const blockBlocks = useBlockProps();

    return (
        <div {...blockBlocks}>
            <InspectorControls>
                <Panel>
                    <PanelBody title="Settings" icon="more" initialOpen={true}>
                        <PanelRow>
                        <PanelRow>
                        <ToggleControl
                            label="Show guest name field"
                            checked={attributes.showGuestName}
                            onChange={(value) => {setAttributes({showGuestName: value})}}
                            help="Show or hide the guest name field"
                            />
                        </PanelRow>
                        </PanelRow>
                    </PanelBody>
                </Panel>
            </InspectorControls>
            <div className="guest-identifier-content">
                <p>Guest identifier pop-up</p>
                {attributes.showGuestName && <TextControl label="Name" />}
                <TextControl label="Phone Number"/>

            </div>
            <ServerSideRender
                block={metadata.name}
                attributes={attributes}
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
