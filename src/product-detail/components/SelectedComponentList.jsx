/* eslint-disable react/prop-types */
import React, {useState, useEffect} from 'react';
import parse from 'html-react-parser';

const SelectedComponentList = ({componentList}) => {

    const [leftSideComponents, setLeftSideComponents] = useState([]);
    const [rigthSideComponents, seRighttSideComponents] = useState([]);
    const [BothSidesComponents, setBothSidesComponents] = useState([]);

    useEffect(() => {
        const leftSide = componentList.filter((component) => component.position === 'left');
        setLeftSideComponents(leftSide);

        const rightSide = componentList.filter((component) => component.position === 'right');
        seRighttSideComponents(rightSide);

        const bothSides = componentList.filter((component) => component.position === 'whole');
        setBothSidesComponents(bothSides);
    }, [componentList]);


    return (
        <div className="components-section">
                                    {BothSidesComponents.length >0 && (<div>
                                        <h3>Both Sides</h3>
                                        <ul id="both-sides-components" className="taglist">
                                            {BothSidesComponents.map((component, index) => (
                                                <li key={index}>{parse(component.name || '')}</li>
                                            ))}
                                        </ul>
                                    </div>)}
                                    
                                    {leftSideComponents.length>0 && (
                                        <div>
                                            <h3>Left Side</h3>
                                            <ul id="left-sides-components" className="taglist">
                                                {leftSideComponents.map((component, index) => (
                                                    <li key={index}>{parse(component.name || '')}</li>
                                                ))}

                                            </ul>
                                        </div>
                                    )}
                                        
                                    {rigthSideComponents.length > 0 && (
                                        <div>
                                            <h3>Right Side</h3>
                                            <ul id="right-sides-components" className="taglist">
                                                {rigthSideComponents.map((component, index) => (
                                                    <li key={index}>{parse(component.name || '')}</li>
                                                ))}
                                            </ul>
                                        </div>)
                                    }
                                </div>
    );
};

export default SelectedComponentList;