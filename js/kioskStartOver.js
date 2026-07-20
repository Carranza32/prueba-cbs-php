const finalizeButton = document.getElementById('finalizeButton');
const handleFinalize =(e) => {
    e.preventDefault();
    console.log("Start Over");
    resetConfig();
    window.location.href = e.target.href;
};

finalizeButton?.addEventListener('click', handleFinalize);

export const resetConfig = () => {
    localStorage.removeItem("OrderType");
    localStorage.removeItem("guestName");
    localStorage.removeItem("guestPhone");
    sessionStorage.removeItem("currentCategorySlug");
    sessionStorage.removeItem("cartOpen");
    sessionStorage.removeItem("reloading");
    deleteCookie('guestPhone');
    deleteCookie('orderType');
}

function deleteCookie(name) {
    document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:01 GMT;';
}