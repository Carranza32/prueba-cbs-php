import React, {useEffect, useState} from "react";
import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls,RichText  } from "@wordpress/block-editor";
import { Panel, PanelBody, PanelRow, SelectControl,ToggleControl } from "@wordpress/components";
import { ReactComponent as DineInIcon } from '../assets/dine-in-icon.svg';
import { ReactComponent as ToGoIcon } from '../assets/to-go-icon.svg';
import { ReactComponent as DeliveryIcon } from '../assets/delivery-icon.svg';

import metadata from "./block.json";
import "./editor.css";
import "./view.css";

function Edit({attributes,setAttributes}) {
    const blockBlocks = useBlockProps();
    const [pages, setPages] = useState([]);
    const [areas, setAreas] = useState([]);
    const { DineIn, ToGo, Delivery } = attributes;
    const orderTypes = [DineIn, ToGo, Delivery];
    const ICONS = {
        DineIn: DineInIcon,
        ToGo: ToGoIcon,
        Delivery: DeliveryIcon,
    };

    useEffect(() => {
        fetch("/wp-json/northstaronlineordering/v1/pages")
        .then((response) => response.json())
        .then((data) => {
            setPages(data);
        });
    }, []);

    useEffect(() => {
        fetch("/wp-json/northstaronlineordering/v1/areas")
        .then((response) => response.json())
        .then((data) => {
            if(data === 'invalid') return;
            setAreas(data);
        });
    }, []);

    return (
        <div {...blockBlocks}>
            <InspectorControls>
                <Panel>
                    <PanelBody title="Settings" icon="more" initialOpen={true}>
                        <PanelRow>
                            <SelectControl
                                label="Select a page to redirect to"
                                value={attributes.redirectPage|| ""}
                                options={pages?.length>0?  pages.map((page) => ({
                                    value: page.slug,
                                    label: page.title,
                                    })): []
                                }
                                onChange={(value) => {
                                    setAttributes({
                                        redirectPage: value,
                                    });
                                }}
                                help="Select a page to redirect to when an order type is selected."
                            />
                        </PanelRow>
                        <PanelRow>
                            <SelectControl
                                label="Select the idle page"
                                value={attributes.idlePage || ""}
                                options={pages?.length>0?  pages.map((page) => ({
                                    value: page.slug,
                                    label: page.title,
                                    })): []
                                }
                                onChange={(value) => {
                                    setAttributes({
                                        idlePage: value,
                                    });
                                }}
                                help="Select a page to redirect to when the user is idle."
                            />
                        </PanelRow>
                        <PanelRow>
                            <SelectControl
                                label="Select the default order type"
                                value={attributes.orderType? attributes.orderType : ""}
                                options={orderTypes?.map((orderType) => ({
                                    value: orderType.value,
                                    label: orderType.customName ||orderType.name,
                                    }))
                                }
                                onChange={(value) => {
                                    setAttributes({
                                        orderType: value,
                                    });
                                }}
                                help="Select the default order type for the block."
                            />
                        </PanelRow>
                        <p>Visibility</p>
                        {orderTypes?.map((orderType) => (
                            <PanelRow key={orderType.value}>
                                <ToggleControl
                                    className="order-type-toggle"
                                    label={`${orderType.name}`}
                                    checked={orderType.enabled}
                                    onChange={(isEnabled) => {
                                        const newOrderType = { ...orderType, enabled: isEnabled };
                                        setAttributes({
                                            [orderType.value]: newOrderType,
                                        });
                                    }}
                                />
                            </PanelRow>
                        ))}
                    </PanelBody>
                </Panel>
            </InspectorControls>
            <div className="order-type-types-container">
                {orderTypes?.map((orderType) => (
                    orderType.enabled &&
                    <button key={orderType.value} className="kiosk-order-type__button">
                        <span className="kiosk-order-type__text">{orderType.name}{orderType.name.includes('Order')? '': " Order Type"}</span>
                        <span className="kiosk-order-type__icon">
                            {React.createElement(ICONS[orderType.value])}
                        </span>
                        <RichText
                        className="kiosk-order-type__custom-name"
                            tagName="span"
                            value={ orderType.customName }
                            onChange={ ( newName ) => {
                                const newOrderType = { ...orderType, customName: newName };
                                setAttributes({
                                    [orderType.value]: newOrderType,
                                });
                            }}
                            placeholder="Type a custom name for this order type"
                        />
                        <SelectControl
                            label="Select the area"
                            value={orderType.area || ""}
                            options={Object.entries(areas || {}).map(([key, value]) => ({
                                value: key,
                                label: value,
                            }))}
                            onChange={(value) => {
                                const newOrderType = { ...orderType, area: value };
                                setAttributes({
                                    [orderType.value]: newOrderType,
                                });
                            }}
                            help="Select the area for this order type."
                        />
                    </button>
                ))}
            </div>
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
