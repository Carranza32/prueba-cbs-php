import * as React from "react";
import { useState, useEffect } from "@wordpress/element";
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { TextControl, Panel, PanelBody, PanelRow , RangeControl, SelectControl } from "@wordpress/components";
import ServerSideRender from "@wordpress/server-side-render";

import metadata from "./block.json";

import "./editor.css";
import "./view.css";

function Edit(props) {
    const blockBlocks = useBlockProps();

    const [data, setData] = useState([]);

    useEffect(() => {
        fetch('/wp-json/northstaronlineordering/v1/sites')
            .then(response => response.json())
            .then(fetchedData => {
                // The /sites endpoint can return a WP_Error-shaped object on
                // failure; keep state array-shaped so the inspector never throws
                // on data.map below.
                setData(Array.isArray(fetchedData) ? fetchedData : []);
            })
            .catch(error => console.error('Error fetching sites:', error));
    }, []);

    const siteOptions = [
        { value: null, label: "None" },
        ...data.map(site => ({
            value: site.siteid,
            label: site.site_name
        }))
    ];

    return (
        <div {...blockBlocks}>
            <InspectorControls>
                <Panel>
                    <PanelBody title="Settings" icon="more" initialOpen={true}>
                        <PanelRow>
                            <SelectControl
                                label="Select Site"
                                value={props.attributes.siteId ? props.attributes.siteId : ""}
                                options={siteOptions}
                                onChange={(value) => {
                                    props.setAttributes({
                                        siteId: value,
                                    });
                                }}
                            />
                        </PanelRow>
                        <PanelRow>
                            <RangeControl
                                label="Number of Products"
                                value={ props.attributes.lenght }
                                onChange={ ( value ) => {
                                    props.setAttributes({
                                        lenght: value,
                                    });
                                }}
                                min={ 0 }
                                max={ 100}
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
