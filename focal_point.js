(function() {
    // Highlight focal point (center) of background images within elements
    function showFocalPointForBackground(elem) {
        const style = getComputedStyle(elem);
        const bgImage = style.backgroundImage;
        if (!bgImage || bgImage === 'none') return;
        const urlMatch = bgImage.match(/url\(("|')?(.*?)("|')?\)/);
        if (!urlMatch) return;
        const img = new Image();
        img.src = urlMatch[2];
        img.onload = function() {
            const elWidth = elem.clientWidth;
            const elHeight = elem.clientHeight;
            if (!elWidth || !elHeight) return;
            const imgWidth = img.naturalWidth;
            const imgHeight = img.naturalHeight;
            const bgSize = style.backgroundSize.split(' ');
            let renderWidth, renderHeight;
            if (bgSize[0] === 'cover' || bgSize[0] === 'contain') {
                const scale = bgSize[0] === 'cover'
                    ? Math.max(elWidth / imgWidth, elHeight / imgHeight)
                    : Math.min(elWidth / imgWidth, elHeight / imgHeight);
                renderWidth = imgWidth * scale;
                renderHeight = imgHeight * scale;
            } else {
                renderWidth = bgSize[0].endsWith('%')
                    ? (parseFloat(bgSize[0]) / 100) * elWidth
                    : parseFloat(bgSize[0]) || imgWidth;
                renderHeight = bgSize[1]
                    ? (bgSize[1].endsWith('%') ? (parseFloat(bgSize[1]) / 100) * elHeight : parseFloat(bgSize[1]))
                    : renderWidth * imgHeight / imgWidth;
            }
            const pos = style.backgroundPosition.split(' ');
            const calcOffset = (val, elemSize, renderSize) => {
                if (val.endsWith('%')) {
                    return (parseFloat(val) / 100) * (elemSize - renderSize);
                }
                if (val.endsWith('px')) {
                    return parseFloat(val);
                }
                switch (val) {
                    case 'left':
                    case 'top':
                        return 0;
                    case 'right':
                    case 'bottom':
                        return elemSize - renderSize;
                    case 'center':
                    default:
                        return (elemSize - renderSize) / 2;
                }
            };
            const offsetX = calcOffset(pos[0] || 'center', elWidth, renderWidth);
            const offsetY = calcOffset(pos[1] || 'center', elHeight, renderHeight);
            const centerX = offsetX + renderWidth / 2;
            const centerY = offsetY + renderHeight / 2;
            const marker = document.createElement('div');
            marker.style.position = 'absolute';
            marker.style.left = centerX + 'px';
            marker.style.top = centerY + 'px';
            marker.style.width = '8px';
            marker.style.height = '8px';
            marker.style.marginLeft = '-4px';
            marker.style.marginTop = '-4px';
            marker.style.borderRadius = '50%';
            marker.style.background = 'red';
            marker.style.zIndex = 9999;
            marker.className = 'bg-focal-point';
            if (getComputedStyle(elem).position === 'static') {
                elem.style.position = 'relative';
            }
            elem.appendChild(marker);
        };
    }
    function showBackgroundFocalPoints(root = document) {
        const elements = root.querySelectorAll('*');
        elements.forEach(el => showFocalPointForBackground(el));
    }
    window.showBackgroundFocalPoints = showBackgroundFocalPoints;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => showBackgroundFocalPoints());
    } else {
        showBackgroundFocalPoints();
    }
})();
