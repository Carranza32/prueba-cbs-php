import React from "react";
import parse from 'html-react-parser';
import ComponentCard from "./ComponentCard";
import RulesList from "./RulesList";
import ComponentCardDropdown from "./ComponentCardDropdown";


export default function ComponentsSwitcher({
  componentsAll = [],
  updateSelectedComponents,
  rootRef,
  view = "tabs",
  accordionAllowMultiple = false,
  categoryValidationError = null,
  componentServingOptionsErrors = {},
}) {


  const [openIds, setOpenIds] = React.useState(() => {
    if (componentsAll && componentsAll.length > 0) {
      return new Set(componentsAll.map(cat => cat.info.componentCatId));
    }
    return new Set();
  });

  React.useLayoutEffect(() => {
    if (componentsAll?.length) {
      const allComponentCatIds = componentsAll.map(cat => cat.info.componentCatId);
      setOpenIds(new Set(allComponentCatIds));
    }
  }, [componentsAll?.length]);
  const toggle = (id) => {
    setOpenIds(prev => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        if (!accordionAllowMultiple) next.clear();
        next.add(id);
      }
      return next;
    });
  };

  const renderTabs = () => (
    <>
      {componentsAll.length > 0 && (
        <div className="no-overflow" ref={rootRef}>
          {
            componentsAll.map(category => {
              const servingOptionErrors = componentServingOptionsErrors[category.info.componentCatId] || [];
              const hasError = categoryValidationError === category.info.componentCatId || servingOptionErrors.length > 0;
              return (
              <div
                id={category.info.componentCatId}
                key={category.info.componentCatId}
                className={`woocommerce-Tabs-panel panel entry-content wc-tab ${hasError ? 'category-validation-error' : ''}`}
                role="tabpanel"
                aria-labelledby={`tab-title-${category.info.componentCatId}`}
              >
                <h3 id={category.info.componentCatId}>{parse(category.info.catName || '')}</h3>
                <div className={`category-rules-info ${hasError ? 'category-rules-error' : ''}`}>
                  <RulesList rules={category.info.rules} />
                  {servingOptionErrors.map((message, i) => (
                    <p key={i} className="component-serving-options-error">{message}</p>
                  ))}
                </div>
                <ul className="products columns-4 list-of-components">
                  {category.items.map(item => (
                    <ComponentCard
                      key={item.componentId}
                      item={item}
                      rules={category.info.rules}
                      updateHandler={updateSelectedComponents}
                    />
                  ))}
                </ul>
              </div>
            )})
          }
        </div>
      )}
    </>
  );


  const renderAccordion = () => (
    <>
      {componentsAll.length > 0 && (
        <div className="no-overflow" ref={rootRef}>
          <div className="accordion" role="tablist" aria-multiselectable={accordionAllowMultiple}>
            {componentsAll.map(category => {
              const id = category.info.componentCatId;
              const isOpen = openIds.has(id);
              const buttonId = `tab-${id}`;
              const panelId = `panel-${id}`;
              const servingOptionErrors = componentServingOptionsErrors[id] || [];
              const hasError = categoryValidationError === id || servingOptionErrors.length > 0;
              return (
                <section key={id} className={`accordion-item ${isOpen ? "is-open" : ""} ${hasError ? 'category-validation-error' : ''}`}>
                  <h3 className="accordion-header" id={id}>
                    <button
                      id={buttonId}
                      className={`accordion-trigger ${hasError ? 'accordion-trigger-error' : ''}`}
                      aria-expanded={isOpen}
                      aria-controls={panelId}
                      onClick={() => toggle(id)}
                      type="button"
                    >
                      {parse(category.info.catName || '')}
                      <span className="accordion-icon" aria-hidden="true">{isOpen ? "−" : "+"}</span>
                    </button>
                  </h3>

                  <div
                    id={panelId}
                    role="region"
                    aria-labelledby={buttonId}
                    className="accordion-panel"
                    hidden={!isOpen}
                  >
                    <div className={`category-rules-info ${hasError ? 'category-rules-error' : ''}`}>
                      <RulesList rules={category.info.rules} />
                      {servingOptionErrors.map((message, i) => (
                        <p key={i} className="component-serving-options-error">{message}</p>
                      ))}
                    </div>
                    <ul className="products columns-4 list-of-components">
                      {category.items.map(item => (
                        <ComponentCardDropdown
                          key={item.componentId}
                          item={item}
                          rules={category.info.rules}
                          updateHandler={updateSelectedComponents}
                        />
                      ))}
                    </ul>
                  </div>
                </section>
              );
            })}
          </div>
        </div>
      )}
    </>
  );

  return view === "accordion" ? renderAccordion() : renderTabs();
}