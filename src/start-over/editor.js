import React from "react";
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { SelectControl, Panel, PanelBody, PanelRow , TextControl } from "@wordpress/components";
import {ReactComponent as StartOverIcon} from "../assets/start-over-icon.svg";

import metadata from "./block.json";
import "./view.css";

function Edit({ attributes, setAttributes}) {
    const blockBlocks = useBlockProps();

    const [pages, setPages] = React.useState([]);
    const [loading, setLoading] = React.useState(true);

    React.useEffect(() => {
        fetch("/wp-json/northstaronlineordering/v1/pages")
            .then((response) => response.json())
            .then((json) => {
                setPages(json);
                setLoading(false);
            });
    }, []);

    return (
        <div {...blockBlocks}>
        <InspectorControls>
                <Panel>
                    <PanelBody title="Settings" icon="more" initialOpen={true}>
                        <PanelRow>
                        <SelectControl
                                label="Select the idle page"
                                value={attributes.idlePageSlug? attributes.redirectPage : ""}
                                options={pages?.length>0?  pages.map((page) => ({
                                    value: page.slug,
                                    label: page.title,
                                })): []}
                                onChange={(value) => {
                                    setAttributes({
                                        idlePageSlug: value,
                                    });
                                }}
                            />
                        </PanelRow>
                        <PanelRow>
                            <SelectControl
                                label="Select device pin page"
                                value={attributes.devicePinSlug? attributes.redirectPage : ""}
                                options={pages?.length>0?  pages.map((page) => ({
                                    value: page.slug,
                                    label: page.title,
                                })): []}
                                onChange={(value) => {
                                    setAttributes({
                                        devicePinSlug: value,
                                    });
                                }}
                            />
                        </PanelRow>
                        <PanelRow>
                            <TextControl
                                value={attributes.time}
                                onChange={(value) => {
                                    setAttributes({
                                        time: Number(value),
                                    });
                                }}
                                label="Time in seconds for reload"
                                placeholer=""
                            />
                        </PanelRow>
                    </PanelBody>
                </Panel>
            </InspectorControls>
            { loading && <p>Loading...</p> }
            {!loading && <div className="start-over-block">
                <p className="start-over-button" >
                <div id="start-over-icon" className="start-over__icon"><StartOverIcon /></div>
                <span className="start-over__text">Start Over</span>
                </p>
            </div>}
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
