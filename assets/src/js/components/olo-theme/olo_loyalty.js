// this file handle the loyalty ui functionality

const loyaltyButton = document.getElementById('loyalty-button');
const loyaltyDialog = document.getElementById('loyalty-dialog');
const loyaltyCloseButton = document.getElementById('rewards-close');
const loader = document.getElementById('loader-box');
const rewardsDoneButton = document.getElementById('rewards-dialog-done');

const rewardButtonTexts = {
    done: 'Done',
    ok: 'OK',
}

let currentLoyaltyData = null;
let programsById = {};
let rewardsListDelegationBound = false;
let rewardChanged = false;

let loading = false;
let redeemState = 0;
let isDialogOpen = false;
let phoneDialogListenersAdded = false;
let loyaltyActionInFlight = false;
let loyaltyBusySnapshot = null;

function setLoyaltyActionsBusy(activeButton) {
    // Snapshot disabled state so a later clearLoyaltyActionsBusy() restores
    // pre-action state instead of indiscriminately enabling buttons that
    // were already disabled (e.g. by canRedeem=false).
    loyaltyBusySnapshot = new Map();
    document.querySelectorAll('#rewards-content .reward-button').forEach(btn => {
        loyaltyBusySnapshot.set(btn, btn.disabled);
        if (btn !== activeButton) btn.disabled = true;
    });
}

function clearLoyaltyActionsBusy() {
    if (!loyaltyBusySnapshot) return;
    loyaltyBusySnapshot.forEach((wasDisabled, btn) => {
        if (document.body.contains(btn)) {
            btn.disabled = wasDisabled;
        }
    });
    loyaltyBusySnapshot = null;
}
const formatPhoneNumber = (phoneNumberString) => {
    const cleaned = ('' + phoneNumberString).replace(/\D/g, '');
    return cleaned.replace(/(\d{0,3})(\d{0,3})(\d{0,4})/, function (_, areaCode, centralOfficeCode, lineNumber) {
        let result = '';
        if (areaCode) result += `(${areaCode}`;
        if (centralOfficeCode) result += `) ${centralOfficeCode}`;
        if (lineNumber) result += `-${lineNumber}`;
        return result;
    });
}

function dinamicRewardsListDelegation() {
    if (rewardsListDelegationBound) return;

    const rewardsList = document.getElementById('rewards-content');
    if (!rewardsList) return;

    rewardsList.addEventListener('click', async (e) => {
        const button = e.target.closest('button');
        if (!button) return;

        if (!button.classList.contains('reward-button')) return;

        e.preventDefault();

        const mode = button.dataset.mode; // "redeem" | "undo"
        const uniqueId = button.dataset.uniqueId;

        if (!uniqueId) return;

        if (mode === 'undo') {
            await deleteRedeem(e, uniqueId);
            return;
        }

        // default to redeem
        const program = programsById[uniqueId];
        if (!program || !currentLoyaltyData) return;

        await redeemReward(e, program, currentLoyaltyData);
    });

    rewardsListDelegationBound = true;
}

function handlePhoneNumberInput (e) {
    if (e.data === null) {
        return;
    }

    const inputValue = e.target.value;
    let cleaned = inputValue.replace(/\D/g, '');
    e.target.value = formatPhoneNumber(cleaned);
}

loyaltyButton?.addEventListener('click', async (e) => {
    e.preventDefault();
    const guestPhonevalue = sessionStorage.getItem('guestPhone');

    if (guestPhonevalue === null) {
        requestCustomerPhoneNumber();
        return;
    }

    handleDisplayLoyaltyDialog();
});

loyaltyCloseButton?.addEventListener('click', closeLoyaltyDialog);
rewardsDoneButton?.addEventListener('click', handleDisplayLoyaltyDialog);

function closeLoyaltyDialog(e) {
    e.preventDefault();
    loyaltyDialog.close();
    handleLoyaltyStateChange();
    isDialogOpen = !isDialogOpen;
}

function handleDisplayLoyaltyDialog() {
    if(!isDialogOpen){
        rewardsDoneButton.innerText = rewardButtonTexts.ok;
        loyaltyDialog?.showModal();
        getLoyaltyData();
    }
    else{
        loyaltyDialog?.close();
        handleLoyaltyStateChange();
    }

    isDialogOpen = !isDialogOpen;
}

function requestCustomerPhoneNumber() {
    const phoneNumberDialog = document.getElementById('phone-number-dialog');
    phoneNumberDialog.showModal();

    if (phoneDialogListenersAdded) {
        return;
    }

    const customerPhoneNumber = document.getElementById('customerPhoneNumber');
    const closebutton = document.getElementById('phone-number-dialog-close');
    const doneButton = document.getElementById('phone-number-done');
    const errorMessage = document.getElementById('phone-error-message');

    phoneDialogListenersAdded = true;

    closebutton?.addEventListener('click', (e) => {
        e.preventDefault();
        phoneNumberDialog.close();
    });

    customerPhoneNumber?.addEventListener('input', handlePhoneNumberInput);
    doneButton?.addEventListener('click', (e) => {
        e.preventDefault();
        const cleaned = customerPhoneNumber.value.replace(/\D/g, '');
        if (cleaned.length < 10) {
            errorMessage.style.display = 'block';
            return;
        }
        errorMessage.style.display = 'none';
        sessionStorage.setItem('guestPhone', customerPhoneNumber.value);
        customerPhoneNumber.value = '';
        phoneNumberDialog.close();
        handleDisplayLoyaltyDialog();
    });
}

async function getLoyaltyData() {
    const guestPhonevalue = sessionStorage.getItem('guestPhone');
    loading = true;

    const cleanedPhone = guestPhonevalue.replace(/\D/g, '');
    const hidePhone = cleanedPhone.slice(-4).padStart(cleanedPhone.length, '*');
    console.log(`Fetching loyalty data for phone number: ${hidePhone}`);
    loader.style.display = 'block';


    const siteId = getCookie('siteIdJs');

    const payload = {
        siteId: siteId,
        phoneNumber: cleanedPhone,
    };

    const baseUrl = '/wp-json/northstaronlineordering/v1/loyalty/availablePrograms';

    const rewardsList = document.getElementById('rewards-content');
    rewardsList.innerHTML = '';

    try {

        const response = await fetch(baseUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            const error = await response.json();
            console.error('Server error:', error.message);
            throw new Error(error.message);
        }
            const data = await response.json();
            loading = false;
            loader.style.display = 'none';
            createRewardsList(data['AvailablePrograms'], data);
            return data;
	} catch (error) {
        loading = false;
        loader.style.display = 'none';

        handleNoRewards(error.message || 'An unexpected error occurred.');
	}
}

function createRewardsList(programs, data) {
    const rewardsList = document.getElementById('rewards-content');
    rewardsList.innerHTML = '';

    dinamicRewardsListDelegation();

    if (!programs || programs.length === 0) {
        rewardsList.innerHTML = '<p>No rewards available</p>';
        resetLoyaltyPhoneNumber();
        return;
    }


    currentLoyaltyData = data;
    programsById = {};
    programs.forEach(p => { programsById[p.uniqueKey] = p; });

    rewardsList.innerHTML = `
        <div class="rewards-row rewards-header" style="display: none;">
            <div class="rewards-column">Product</div>
            <div class="rewards-column"></div>
            <div class="rewards-column"></div>
        </div>`;

        const canRedeem = data.canRedeem;
    programs.forEach(reward => {
        const div = document.createElement('div');

        const isRedeemed = !!reward.redeemed;
        const buttonText = isRedeemed ? 'Undo' : 'Redeem';

        const classes = [
            'reward-button',
            'reward-action',
            isRedeemed ? 'undo' : 'redeem-button',
            isRedeemed ? 'done' : ''
        ].filter(Boolean).join(' ');

        div.className = 'rewards-row ticket';
        div.innerHTML = `
            <div class="rewards-column">${reward.name}</div>
            <div class="rewards-column"></div>
            <div class="rewards-column">
                <button
                    class="${classes}"
                    data-program-id="${reward.ProgramId}"
                    data-unique-id="${reward.uniqueKey}"
                    data-mode="${isRedeemed ? 'undo' : 'redeem'}"
                    ${!isRedeemed && !canRedeem ? 'disabled' : ''}
                >
                    ${buttonText}
                </button>
            </div>
        `;

        rewardsList.appendChild(div);
    });

    rewardsList.style.display = 'block';
}

async function redeemReward(e, program, data) {
    e.preventDefault();
    const button = e.target.closest('button');
    if (!button) return;
    if (loyaltyActionInFlight) return;

    loyaltyActionInFlight = true;
    redeemState = 1;
    button.disabled = true;
    button.innerHTML = `<span class="loader"></span>`;
    setLoyaltyActionsBusy(button);

    if (redeemState === 1) {
        const prevButtonContent = e.target.innerHTML;
        e.target.disabled = true;
        e.target.innerHTML= `<span class="loader"></span>`;
        const points = program.points

        const baseUrl = '/wp-json/northstaronlineordering/v1/loyalty/redeem';
        const siteId = getCookie('siteIdJs');

        const bodyData = {
            siteId: siteId,
            program: program,
            loyalty: data,
            points: points,
        };

        try {
            const response = await fetch(baseUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(bodyData)
            });
            if (!response.ok) {
                const error = await response.json();
                console.error('Server error:', error.message);
                throw new Error(error.message);
            }

            if (response.ok) {
                const data = await response.json();
                console.info(data);
                clearLoyaltyActionsBusy();
                handleSuccessRedeem(data, button);
                redeemState= 0;
                return data;
            }
        } catch (error) {
            console.error('Error:', error);
            redeemState= 0;
            button.disabled = false;
            button.innerHTML = prevButtonContent;
            clearLoyaltyActionsBusy();
            console.error('POST request failed:', error);
        } finally {
            loyaltyActionInFlight = false;
        }
    }
}

async function deleteRedeem(e, uniqueId) {
    e.preventDefault();

    const button = e.target.closest('button');
    if (!button) return;
    if (loyaltyActionInFlight) return;

    loyaltyActionInFlight = true;
    const prevButtonContent = button.innerHTML;
    button.disabled = true;
    button.innerHTML = `<span class="loader"></span>`;
    setLoyaltyActionsBusy(button);

    const baseUrl = '/wp-json/northstaronlineordering/v1/loyalty/undoRedeem';

    const bodyData = {
        programId: button.dataset.programId,
        uniqueKey: uniqueId,
    };

        try {
            const response = await fetch(baseUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(bodyData)
            });

            if (response.ok) {
                const data = await response.json();
                clearLoyaltyActionsBusy();
                handleSuccessUndo(button.dataset.programId, button);
                updateRewardButtons(data);
                return data;
            } else {
                throw new Error(`Error undoing reward redemption: ${response.status}`);
            }
        } catch (error) {
            console.error('Error undoing redemption:', error);
            e.target.disabled = false;
            e.target.innerHTML = prevButtonContent;
            clearLoyaltyActionsBusy();
        } finally {
            loyaltyActionInFlight = false;
        }
}

function handleSuccessRedeem(responseData, button) {
    button.innerHTML = 'Undo';
    button.disabled = false;

    button.dataset.mode = 'undo';
    button.classList.remove('redeem-button');
    button.classList.add('undo', 'done');

    const programId = button.dataset.programId || responseData.programId;
    const uniqueId = button.dataset.uniqueId;
    if (programId && programsById[uniqueId]) {
        programsById[uniqueId].redeemed = true;
    }
    rewardChanged = true;
    updateRewardButtons(responseData);

}

function handleSuccessUndo(programId, button) {
    button.innerHTML = 'Redeem';
    button.disabled = false;

    button.dataset.mode = 'redeem';

    button.classList.remove('undo', 'done');
    button.classList.add('redeem-button');

    const uniqueId = button.dataset.uniqueId;
    if(uniqueId && programsById[uniqueId]) {
        programsById[uniqueId].redeemed = false;
    }
    rewardChanged = true;
}

function handleNoRewards(errorMessage = '') {
    const rewardsList = document.getElementById('rewards-content');
    rewardsList.innerHTML = '';
    const paragraph = document.createElement('p');
    paragraph.textContent = `No rewards available: ${errorMessage}`;
    rewardsList.appendChild(paragraph);
    rewardsList.style.display = 'block';
    resetLoyaltyPhoneNumber();
}

jQuery(document).ready(function($) {
    $(document.body).on('update_checkout', function() {
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url.indexOf('wc-ajax=update_order_review') > -1) {
                if (loading) {
                    console.log('updating');

                }
            }
        });
    });
});

function getCookie(name) {
  const cookies = document.cookie.split('; ');
  for (const cookie of cookies) {
    const [key, value] = cookie.split('=');
    if (key === name) {
      return decodeURIComponent(value);
    }
  }
  return null;
}

function resetLoyaltyPhoneNumber() {
    sessionStorage.removeItem('guestPhone');
}

function updateRewardButtons(rewardData) {
    if (!rewardData) return;

    const rawBalance = rewardData.Balance ?? rewardData.BalanceAfterUndo;

    if (rawBalance === null) return;
    const balance = Number(rawBalance);
    if(isNaN(balance)) return;

    const buttons = document.querySelectorAll('.reward-button');
    buttons.forEach(button => {
        if (button.dataset.mode === 'redeem') {
            button.disabled = balance <= 0;
        }
    });
}

function handleLoyaltyStateChange()  {
    if(rewardChanged) {
        jQuery(document.body).trigger('update_checkout');
        rewardChanged = false;
        rewardsDoneButton.innerText = rewardButtonTexts.done;
    }

}