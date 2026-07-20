function validateInputNumber(input) {
    let phoneNumber = formatPhoneNumber(input.value);
    input.value =  phoneNumber;

}
function formatPhoneNumber(input) {

    let phoneNumber = input.replace(/\D/g, '');

    if (phoneNumber.length > 10) {
        // Format as (XXX) XXX-XXXX
        return phoneNumber.replace(/(\d{3})(\d{3})(\d{4})/, '($1) $2-$3');
    } else {
        // Format as XXX-XXX-XXXX
        return phoneNumber.replace(/(\d{3})(\d{3})(\d{4})/, '$1-$2-$3');
    }
}
function addDigit(digit) {
    var numberField = document.getElementById("guestPhone");
    if (numberField.value.length < 10) {
        let formattedNumber = numberField.value + digit;
        console.log(formattedNumber);
        numberField.value = formatPhoneNumber(formattedNumber);
    }
}
function removeDigit() {
    var numberField = document.getElementById("guestPhone");
    var number = numberField.value;
    numberField.value = number.substring(0, number.length - 1);
}

function getCookieValue(cookieName) {

    var cookies = document.cookie.split(';');


    for (var i = 0; i < cookies.length; i++) {
        var cookie = cookies[i].trim();

        if (cookie.indexOf(cookieName + '=') === 0) {
            return cookie.substring(cookieName.length + 1);
        }
    }

    // If the cookie was not found, return null
    return null;
}

function showGuestIdentifier(){
    var guestIdentifier = document.getElementById("guestIdentifier");
    var products = document.getElementById("mainDiv");
    products.style.display = "none";
    guestIdentifier.style.display = "flex";
}