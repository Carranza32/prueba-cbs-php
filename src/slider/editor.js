import * as React from "react";
import { useState , useEffect} from "react";
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { TextControl, Panel, PanelBody, PanelRow  , SelectControl , ToggleControl} from "@wordpress/components";
import ServerSideRender from "@wordpress/server-side-render";
import { Button } from '@wordpress/components';
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';

import metadata from "./block.json";

import "./editor.css";
import "./view.css";

function Edit(props) {
    const [products, setProducts] = useState([]);


    useEffect(() => {
        const fetchData = async () => {
            const response = await fetch('/wp-json/northstaronlineordering/v1/products');
            const data = await response.json();
            const productsData = [ { value: null, label: "None" } , ...data.map(item => ({
                value: item.id,
                label: item.name
            }))];
            setProducts(productsData);
        }
        fetchData();
    }, [props]);
    const blockBlocks = useBlockProps();


    const handleDropdownChange = (id, value) => {
        console.log("value" , value);
        const updatedDropdowns = props.attributes.dropdowns.map(dropdown =>
            dropdown.id === id ? { ...dropdown, selected: value } : dropdown
        );
        props.setAttributes({ dropdowns: updatedDropdowns });
      };
    
    const handleDropdownChangeProduct = (id , value) => { 
       
        const updatedDropdowns = props.attributes.dropdowns.map( dropdown => 
            dropdown.id === id ? { ...dropdown, menuitem: value } : dropdown 
         );
         props.setAttributes({ dropdowns: updatedDropdowns});
    }

    const deleteSlide = (id) => {
        const dropdownToDelete = props.attributes.dropdowns.find(dropdown => dropdown.id === id);
        if (!dropdownToDelete) return;
        
        const positionToDelete = dropdownToDelete.position;
    
        const updatedDropdowns = props.attributes.dropdowns
            .filter(dropdown => dropdown.id !== id)
            .map(dropdown => {
                if (dropdown.position > positionToDelete) {
                    return { ...dropdown, position: dropdown.position - 1 };
                }
                return dropdown;
            });
        props.setAttributes({ dropdowns: updatedDropdowns });
    }
    
      
    const handlePositionChange = (id, value) => {
        const maxPosition = props.attributes.dropdowns.length;
        const newPosition = Number(value);
        let currentPosition = "";
        let currentValue = "";
    
        const currentDropdown = props.attributes.dropdowns.map(dropdown => {
            if (dropdown.position ===  newPosition) {
                currentPosition = dropdown.id; 
            }
        });

        const currentDropdownValue = props.attributes.dropdowns.map(dropdown => {
            if (dropdown.id === id){
                currentValue = dropdown.position;
            }
        }
        );


        const updatedValues = props.attributes.dropdowns.map(dropdown => {
            if (dropdown.id === id) {
                return { ...dropdown, position: newPosition};
            }
            if(dropdown.id === currentPosition){
                return { ...dropdown, position: currentValue};
            }
            return dropdown;
        }
        );

        props.setAttributes({ dropdowns: updatedValues });
    }
    

    const handleAddDropdown = () => {
        const maxId = props.attributes.dropdowns.reduce((max, dropdown) => Math.max(max, dropdown.id), 0);

        const newDropdown = {
            id: maxId + 1,
            selected: '',
            position: props.attributes.dropdowns.length + 1,
            menuitem: "0",
            imageUrl: ""
        };

        const updatedDropdowns = [...props.attributes.dropdowns, newDropdown];
        props.setAttributes({ dropdowns: updatedDropdowns });
    };

    const handleImageUpload = (id, media) => {
        const updatedDropdowns = props.attributes.dropdowns.map(dropdown =>
          dropdown.id === id ? { ...dropdown, imageUrl: media.url } : dropdown
        );
        props.setAttributes({ dropdowns: updatedDropdowns });
      };



      return (
        <div {...blockBlocks}>
            <InspectorControls>
                <Panel>
                    <PanelBody title="Slides Settings" icon="more" initialOpen={false}>
                        {props.attributes?.dropdowns?.map((dropdown) => (
                            <React.Fragment key={dropdown.id}>
                                <PanelRow className="cbs-setting-dropdown">
                                    <SelectControl
                                        label={`Slide ${dropdown.id}`}
                                        value={dropdown.selected}
                                        options={[
                                            { label: 'Quick Add', value: 1 },
                                            { label: 'Custom Image', value: 2 }
                                        ]}
                                        onChange={(value) => {
                                            handleDropdownChange(dropdown.id, value);
                                        }}
                                    />
                                    <SelectControl
                                        label={`Position `}
                                        value={dropdown.position}
                                        options={props.attributes.dropdowns.map((d) => ({
                                            label: d.position,
                                            value: d.position
                                        }))}
                                        onChange={(value) => {
                                            handlePositionChange(dropdown.id, value);
                                        }}
                                    />
                                </PanelRow>
                                <PanelRow>
                                    <MediaUploadCheck>
                                        <MediaUpload
                                            onSelect={(media) => handleImageUpload(dropdown.id, media)}
                                            allowedTypes={['image']}
                                            value={dropdown.imageUrl}
                                            render={({ open }) => (
                                                <Button onClick={open}>Add Media</Button>
                                            )}
                                        />
                                    </MediaUploadCheck>
                                    {dropdown.imageUrl && (
                                        <img className="cbs-config-img" src={dropdown.imageUrl} alt="Uploaded" />
                                    )}
                                </PanelRow>
                                {dropdown.selected === "1" && (
                                    <PanelRow>
                                        <SelectControl
                                            label={`Product ${dropdown.id}`}
                                            value={dropdown.menuitem}
                                            options={products}
                                            onChange={(value) => {
                                                handleDropdownChangeProduct(dropdown.id, value);
                                            }}
                                        />
                                    </PanelRow>
                                )}
                                <PanelRow>
                                    <button onClick={() => deleteSlide(dropdown.id)}>Delete Slide</button>
                                </PanelRow>
                            </React.Fragment>
                        ))}
                        <PanelRow>
                            <button onClick={handleAddDropdown}>+</button>
                        </PanelRow>
                    </PanelBody>
                    <PanelBody title="Url Settings" icon="more" initialOpen={false}>
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
                    <PanelBody title="Time Settings" icon="more" initialOpen={false}>
                        <PanelRow>
                            <TextControl
                                value={props.attributes.interval}
                                onChange={(value) => {
                                    props.setAttributes({
                                        interval: Number(value),
                                    });
                                }}
                                label="Interval time per slide (seconds)"
                                placeholer="add the interval in seconds"
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
