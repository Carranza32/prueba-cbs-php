import React from "react";
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps,InspectorControls } from '@wordpress/block-editor';
import { Panel, PanelBody, PanelRow, SelectControl ,ToggleControl } from "@wordpress/components";
import { useEffect, useState } from '@wordpress/element';
import ServerSideRender from "@wordpress/server-side-render";

import metadata from "./block.json";
import "./editor.css";
import "./view.css";


function Edit(props) {

    const blockBlocks = useBlockProps();

    const [products, setProducts] = useState([]);

    const modalStates = [
        { value: 'visible', label: 'Show' },
        { value: 'hide', label: 'Hide' },
    ];

    useEffect(() => {
        const fetchData = async () => {
            const response = await fetch('/wp-json/northstaronlineordering/v1/products');
            const data = await response.json();
            setProducts(data);
        }
        fetchData();
    }, []);

	return (
        <div { ...blockBlocks }>
            <InspectorControls>
                <Panel>
                    <PanelBody title="Modal Settings" icon="more" initialOpen={true}>
                        <PanelRow>
                            <SelectControl
                                label="Select a product to preview"
                                value={props.attributes.productId ? props.attributes.productId : ""}
                                options={products.map(product => ({
                                    value: product.id,
                                    label: product.name
                                }))}
                                onChange={(value) => {
                                    props.setAttributes({
                                        productId: value,
                                    });
                                }}
                            />
                        </PanelRow>
                        <InspectorControls>
                        <Panel>
                            <PanelBody title="Settings" icon="more" initialOpen={true}>
                                <PanelRow>
                                <PanelRow>
                                <ToggleControl
                                    label="Show components dropdown"
                                    checked={props.attributes.components}
                                    onChange={(value) => { 
                                        props.setAttributes({
                                                components: value,
                                            });}}
                                    help="Show normal components dropdown instead of cards."
                                    />
                                </PanelRow>
                                </PanelRow>
                            </PanelBody>
                        </Panel>
                        </InspectorControls>
                        <PanelRow>
                            <SelectControl
                                label="Show preview"
                                value={props.attributes.visibility ? props.attributes.visibility : "hide"}
                                options={modalStates.map(modalState => ({
                                    value: modalState.value,
                                    label: modalState.label
                                }))}
                                onChange={(value) => {
                                    props.setAttributes({
                                        visibility: value,
                                    });
                                }}
                            />
                        </PanelRow>
                    </PanelBody>
                </Panel>
            </InspectorControls>
            <p>Product detail</p>
            <ServerSideRender
                block={metadata.name}
                attributes={props.attributes} />
        </div>
	);
}


registerBlockType(metadata.name, {
    attributes: metadata.attributes,
    title: metadata.title,
    category: metadata.category,
    edit: Edit,
    save: () => {
        return null;
    }
});