import React from "react";
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faChevronRight} from "@fortawesome/free-solid-svg-icons";

const Breadcrumbs = ({parent,currentCategory, onParentClick}) => {
    const handleParentClick = (event) => {
        event.preventDefault();
        if(onParentClick) {
            onParentClick(parent);
        }
    };

    if(!parent) return null;

    return (
        <nav className="breadcrumbs">
            <button 
                onClick={handleParentClick}
                className="breadcrumb-parent-btn"
            >
                {parent}
            </button>
            <span className="breadcrumb-separator"> 
                <FontAwesomeIcon icon={faChevronRight} />
            </span>
            <span className="breadcrumb-current"> {currentCategory}</span>
        </nav>
    )
}

export default Breadcrumbs;