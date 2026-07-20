import React, {useState, useEffect} from "react";
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { SelectControl, Panel, PanelBody, PanelRow  } from "@wordpress/components";
import ServerSideRender from "@wordpress/server-side-render";
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { Button } from '@wordpress/components';

import metadata from "./block.json";
import "./editor.css";
import "./view.css";

function Edit ({attributes,setAttributes}) {
    const blockBlocks = useBlockProps();
    const [pages, setPages] = useState([]);

    const handleImageUpload = (media) => {
        setAttributes({ logo: media });
      };

    useEffect(() => {
        fetch("/wp-json/northstaronlineordering/v1/pages")
        .then((response) => response.json())
        .then((data) => {
            setPages(data);
        });
    }, []);

    return (
        <div {...blockBlocks}>
            <InspectorControls>
                <Panel>
                    <PanelBody title="Settings" icon="more" initialOpen={true}>
                    <PanelRow>
                        <MediaUploadCheck>
                                        <MediaUpload
                                            onSelect={(media) => {
                                                setAttributes({ logo: media.url });
                                            }}
                                            allowedTypes={['image']}
                                            value={attributes.logo}
                                            render={({ open }) => (
                                                <Button onClick={open}>Add Media</Button>
                                            )}
                                        />
                                    </MediaUploadCheck>
                                    {attributes.logo && (
                                        <img className="cbs-config-img" src={attributes.logo} alt="Uploaded" />
                                    )}
                            </PanelRow>
                        <PanelRow>
                            <SelectControl
                                label="Select a page to redirect to"
                                value={attributes.redirectPage? attributes.redirectPage : ""}
                                options={pages?.length>0?  pages.map((page) => ({
                                    value: page.slug,
                                    label: page.title,
                                })): []}
                                onChange={(value) => {
                                    setAttributes({
                                        redirectPage: value,
                                    });
                                }}
                            />
                        </PanelRow>
                    </PanelBody>
                </Panel>
            </InspectorControls>
            <ServerSideRender
                block={metadata.name}
                attributes={attributes}
            />
        </div>
    );
};

registerBlockType(metadata.name, {
    attributes: metadata.attributes,
    title: metadata.title,
    category: metadata.category,
    edit: Edit,
    save() {
        return null;
    },
});
