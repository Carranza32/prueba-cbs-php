import { useEffect, useState, useRef} from 'react'

export default function useNearScreen({rootRef} ={}) {
  const [isNearScreen, setIsNear] = useState(false)
  const [inView, setInView] = useState({});
  const fromRef = useRef()
  const irootRef = useRef()
  
  const [activeIndex, setActiveIndex] = useState('');
  useEffect(function(){

    if(!rootRef || !rootRef?.current) return
    const componentCategoriesTitles = rootRef.current.querySelectorAll('div.panel');

    let observer
    const onChange = (entries, observer) => {
      let prevInView = inView;
      entries.forEach(entry => {
        if(entry.isIntersecting) {
          if (entry.boundingClientRect.height > entry.rootBounds.height) {
            // Entry is bigger than the root
            if (entry.intersectionRatio >= 0.2) {
                setIsNear(true);
                setActiveIndex(entry.target.id);
            } else {
                setIsNear(false);
            }
        } else {
            // Entry is smaller than the root
            if (entry.intersectionRatio >= 0.7) {
                setIsNear(true);
                setActiveIndex(entry.target.id);
            } else {
                setIsNear(false);
            }
        }
        } else if (!entry.isIntersecting) {
          setIsNear(false);
        }
      });
      setInView(prevInView);
    }
    
    observer = new IntersectionObserver(onChange, { root: rootRef.current, threshold: [0.2,0.7]})
    componentCategoriesTitles.forEach(element => observer.observe(element))
    return () => {
      componentCategoriesTitles.forEach(element => observer.unobserve(element))
    }
  })
  return {isNearScreen, fromRef, irootRef, activeIndex}
}