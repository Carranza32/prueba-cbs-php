import React, {useState, useEffect} from 'react';
import RedeemButton from './RedeemButton';

const AvailablePrograms = ({loyalty, siteId, redeemCountHandler, cartTotal, onBusyChange}) => {

    const programs = loyalty?.AvailablePrograms || [];
    const [busyKey, setBusyKey] = useState(null);
    const [pendingCheckoutSync, setPendingCheckoutSync] = useState(false);

    useEffect(() => {
        onBusyChange && onBusyChange(busyKey !== null);
    }, [busyKey, onBusyChange]);

    // Hold busy lock until the WC update_checkout cycle completes so cartTotal
    // refreshes BEFORE other Redeem buttons re-enable; otherwise a $0 reward
    // would briefly leave peers clickable in the window between fetch success
    // and the WC ajax response.
    useEffect(() => {
        if (!pendingCheckoutSync) return;
        const onUpdated = () => {
            setBusyKey(null);
            setPendingCheckoutSync(false);
        };
        jQuery(document.body).on('updated_checkout', onUpdated);
        return () => {
            jQuery(document.body).off('updated_checkout', onUpdated);
        };
    }, [pendingCheckoutSync]);

    if(!programs || programs.length === 0){
        return <div className="rewards-dialog-content">No rewards available</div>;
    }
    return (
        <div className="rewards-dialog-content">
            {programs.map((program) => {
                const key = program.uniqueKey || program.ProgramId;
                const disabledByOther = busyKey !== null && busyKey !== key;
                return (
                    <div className="rewards-row ticket" key={key}>
                        <div className="rewards-column">{program.name}</div>
                        <div className="rewards-column"></div>
                        <div className="rewards-column">
                            <RedeemButton
                                program={program}
                                loyalty={loyalty}
                                siteId={siteId}
                                redeemCounter={redeemCountHandler}
                                cartTotal={cartTotal}
                                disabledByOther={disabledByOther}
                                onActionStart={() => setBusyKey(key)}
                                onActionEnd={(success) => {
                                    if (success) {
                                        setPendingCheckoutSync(true);
                                    } else {
                                        setBusyKey(null);
                                    }
                                }}
                            />
                        </div>
                    </div>
                );
            })}
        </div>
    );
};

export default AvailablePrograms;