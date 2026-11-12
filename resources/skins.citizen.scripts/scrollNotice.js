module.exports = function () {
    const scrollDiv = document.getElementById( "scrollDiv" );
    if ( !scrollDiv ) return;

    const ul = scrollDiv.querySelector( "ul" );
    if ( !ul ) return;

    setInterval( function () {
        if ( document.hidden ) return;

        const animation = ul.animate(
            [ { marginTop: "0em" }, { marginTop: "-1.5em" } ],
            { duration: 500, easing: "ease-in-out" }
        );

        animation.onfinish = function () {
            ul.style.marginTop = "0em";
            const firstLi = ul.querySelector( "li" );
            if ( firstLi ) ul.appendChild( firstLi );
        };
    }, 5000 );
};