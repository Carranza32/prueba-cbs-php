import React, {useState, useEffect} from 'react';

const RedeemButton = ({ program, loyalty, siteId, redeemCounter, cartTotal, disabledByOther, onActionStart, onActionEnd }) => {
    const [redeemState, setRedeemState] = useState(0);  // 0 = not redeemed, 1 = redeeming, 2 = redeemed

    useEffect(() => {
        console.log("Program state changed");
        if (program?.redeemed === true) {
            setRedeemState(2);
        }
    }, [program]);

    useEffect(() => {
        if (redeemState === 1) {
            const percent = program?.name?.includes('off') ? program.name.split('% ')[0] : 0;
            const foronly = program?.name?.includes('for') ? program.name.split('for $')[1] : 0;

            let redeemAmount = 0;

            if (percent > 0) {
                redeemAmount = (program.totalCheckAmount || 10) * percent / 100;
            } else if (foronly > 0) {
                redeemAmount = foronly;
            } else {
                redeemAmount = 5;
            }

            fetch(`/wp-json/northstaronlineordering/v1/loyalty/redeem`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    siteId,
                    loyalty,
                    program
                }),
            })
            .then(async response => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data?.ErrorMessage || data?.code) {
                    const message = data?.ErrorMessage || data?.message || `HTTP ${response.status}`;
                    throw new Error(message);
                }
                return data;
            })
            .then(data => {
                console.log(data);
                setRedeemState(2);
                redeemCounter(prev => prev + 1);
                jQuery(document.body).trigger('update_checkout');
                onActionEnd && onActionEnd(true);
            })
            .catch(error => {
                console.error('Error:', error);
                setRedeemState(0);
                onActionEnd && onActionEnd(false);
            });
        }
    }, [redeemState]);


    async function deleteRedeem(e, programId, uniqueKey) {
    e.preventDefault();


    const btn = e.currentTarget;
    const prevButtonContent = btn.innerHTML;

    btn.disabled = true;
    setRedeemState(3);
    onActionStart && onActionStart();

    const baseUrl = '/wp-json/northstaronlineordering/v1/loyalty/undoRedeem';
    const bodyData = { programId, uniqueKey };

    try {
      const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify(bodyData),
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok || data?.ErrorMessage || data?.code) {
        const message = data?.ErrorMessage || data?.message || `HTTP ${response.status}`;
        throw new Error(message);
      }
      console.log('Undo response:', data);


      setRedeemState(0);
      redeemCounter(prev => Math.max(0, prev - 1));
       jQuery(document.body).trigger('update_checkout');
       onActionEnd && onActionEnd(true);

      return data;
    } catch (error) {
      console.error('Error undoing redemption:', error);

      btn.disabled = false;
      btn.innerHTML = prevButtonContent;
      setRedeemState(2);
      onActionEnd && onActionEnd(false);
    }
  }

    const handleRedeem = () => {
        onActionStart && onActionStart();
        setRedeemState(1);
    };

    return (
        <>
            {redeemState === 2 && (
                <button
                className='reward-button done undo'
                onClick={(e) => deleteRedeem(e, program?.ProgramId, program?.uniqueKey)}
                disabled={disabledByOther}
                >
                Undo
                </button>
            )}

            {(redeemState === 1 || redeemState === 3) && (
                <button className='reward-button loading' disabled>
                    <span className="loader"></span>
                </button>
            )}

            {redeemState === 0 && (
                <button
                    className='reward-button'
                    onClick={handleRedeem}
                    disabled={(typeof cartTotal === 'number' && cartTotal <= 0) || disabledByOther}
                    title={typeof cartTotal === 'number' && cartTotal <= 0 ? 'Total reached $0' : undefined}
                >
                    Redeem
                </button>
            )}
        </>
    );
};

export default RedeemButton;