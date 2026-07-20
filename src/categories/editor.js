import React, { useState, useEffect } from 'react';
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { TextControl, Panel, PanelBody, PanelRow , ToggleControl , SelectControl } from "@wordpress/components";
import ServerSideRender from "@wordpress/server-side-render";
import placeholder from './asset/placeholder.png';

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
                console.log('Fetched data:', fetchedData);
                setData(fetchedData);
                const siteIdExists = fetchedData.some(site => site.siteid === props.attributes.siteId);
                if (siteIdExists) {
                    console.log(`SiteId ${props.attributes.siteId} exists in the data.`);
                } else {
                    console.log(`SiteId ${props.attributes.siteId} does not exist in the data.`);
                    props.setAttributes({
                        siteId: "none",
                    });
                }
            })
            .catch(error => console.error('Error fetching data:', error));
    }, []);

    const siteOptions = [
        { value: null, label: "None" },
        ...data.map(site => ({
            value: site.siteid,
            label: site.site_name
        }))
    ];

    const handleOrientationChange = newValue => {
        props.setAttributes({ flexDirection: newValue });
    };


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
                        <ToggleControl
                            label="Orientation"
                            checked={props.attributes.flexDirection}
                            onChange={handleOrientationChange}
                            help={props.attributes.flexDirection ? 'Vertical' : 'Horizontal'}
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
