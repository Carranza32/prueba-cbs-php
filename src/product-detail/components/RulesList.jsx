import React from 'react';

const RulesList = React.memo(({rules})=> {
    
    return (
        <ul style={{color:"red"}}>
            {Object.keys(rules).map((key, index) => {
                if(key ==='Default') {return null;}
                const {FreeUpTo,FreeAfter, MinRequired, MaxUnique,MaxAllowed } = rules[key];
                return (
                    <React.Fragment key={`${key}-${index}`}>
                        {FreeUpTo === 1 && <li>First component is free</li>}
                        {FreeUpTo >1 && <li>First {FreeUpTo} components are free</li>}
                        {FreeAfter >0 && <li>Components after {FreeAfter} are free</li>}
                        {MinRequired > 0 && <li>Please select at least {MinRequired} item</li>}
                        {MaxUnique > 0 && <li>Allowed per each component: {MaxUnique}</li>}
                        {MaxAllowed && MaxAllowed !== 1000 && <li>Max allowed {MaxAllowed}</li>}
                    </React.Fragment>
                );
            })}
        </ul>
    );
});

RulesList.displayName = 'RulesList';
export default RulesList;