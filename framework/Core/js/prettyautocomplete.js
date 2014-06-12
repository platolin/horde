/**
 * An autocompleter implementation that provides a UI similar to Apple's Mail
 * To: field where entered items are represented as bubbles etc...
 *
 * @author     Michael J Rubinsky <mrubinsk@horde.org>
 * @copyright  2008-2014 Horde LLC
 * @license    LGPL-2.1 (http://www.horde.org/licenses/lgpl21)
 *
 * @todo H6 Extract common code between this class and IMP's version. Use custom
 * events instead of passing handlers.
 */

var PrettyAutocompleter = Class.create({

    // Autocompleter
    aac: null,

    // Delete image element.
    dimg: null,

    enabled: false,

    // required params:
    //   deleteImg
    //   uri
    initialize: function(element, params)
    {
        this.p = Object.extend({
            // Outer div/fake input box and CSS class
            box: 'HordeACBox',
            boxClass: 'hordeACBox',
            // <ul> CSS class
            listClass: 'hordeACList',
            listClassItem: 'hordeACListItem',
            // CSS class for real input field
            growingInputClass: 'hordeACTrigger',
            removeClass: 'hordeACItemRemove',
            // Allow for a function that filters the display value
            // This function should *always* return escaped HTML
            displayFilter: function(t) { return t.escapeHTML(); },
            filterCallback: this.filterChoices.bind(this),
            onAdd: Prototype.K,
            onRemove: Prototype.K,
            requireSelection: false,
            existing: []
        }, params || {});

        // The original input element is transformed into the hidden input
        // field that hold the text values (this.elm), while p.trigger is
        // the borderless input field located in p.box
        this.elm = $(element);
        this.p.trigger = element + 'real';
        this.initialized = false;
        this.enabled = true;
    },

    blur: function()
    {
        this.processValue();
        this.resize();
    },

    /**
     * Initializes the autocompleter, builds the dom structure, registers
     * events, etc...
     */
    init: function()
    {
        if (this.initialized) {
            return;
        }

        // Build the DOM structure
        this.buildStructure();

        // Make sure the original input box element is hidden
        if (!this.p.debug) {
            this.elm.hide();
        }

        // Set the updateElement callback to pass to the Autocompleter.
        this.p.onSelect = this.updateElement.bind(this);

        // Look for clicks on the box to simulate clicking in an input box
        this.box.observe('click', this.clickHandler.bindAsEventListener(this));
        this.box.observe('dblclick', this.dblclickHandler.bindAsEventListener(this));

        // Remember the bound method to unregister later.
        this.boundProcessValue = this.blur.bind(this);
        this.input.observe('blur', this.boundProcessValue);
        this.input.observe('keydown', this.resize.bind(this));
        this.input.observe('keypress', this.resize.bind(this));
        this.input.observe('keyup', this.resize.bind(this));
        this.input.observe('keydown', this.keyDownHandler.bindAsEventListener(this));

        // Create the underlaying Autocompleter
        this.p.uri += '&input=' + this.p.trigger;

        this.p.onShow = this.knlShow.bind(this);
        this.p.onHide = this.knlHide.bind(this);

        // Make sure the knl is contained in the overlay
        this.p.domParent = this.p.box;
        this.aac = new Ajax.Autocompleter(this.p.trigger, this.p.uri, this.p);
        this.initialized = true;

        // Prepopulate the items and the container elements?
        this.reset(this.p.existing);
    },

    /**
     * Resets the autocompleter's state.
     */
    reset: function(existing)
    {
        if (!this.initialized) {
            this.init();
        }
        this.currentEntries().each(function(elt) {
            this.removeItemNode(elt);
        }.bind(this));
        this.updateInput('');

        // Add any initial values
        existing = existing || [];
        if (existing.length) {
            for (var i = 0, l = existing.length; i < l; i++) {
                this.addNewItemNode(existing[i]);
            }
        }
        this.enabled = true;
    },

    buildStructure: function()
    {
        // Build the outter box
        this.box = new Element('div', {
            id: this.p.box,
            className: this.p.boxClass
        }).setStyle({ position: 'relative' });

        this.input = new Element('input', {
            className: this.p.growingInputClass,
            id: this.p.trigger,
            name: this.p.trigger,
            autocomplete: 'off'
        });

        this.box.insert(
            // The list - where the choosen items are placed as <li> nodes
            new Element('ul', { className: this.p.listClass }).insert(
                new Element('li').update(this.input)
            )
        );

        // Replace the single input element with the new structure and
        // move the old element into the structure while making sure it's
        // hidden. (Use the long form to play nice with Opera)
        this.box.insert(Element.replace(this.elm, this.box));
    },

    shutdown: function()
    {
        this.processValue();
    },

    keyDownHandler: function(e)
    {
        // Check for a comma or enter
        if ((e.keyCode == 188 || (this.honorReturn() && e.keyCode == Event.KEY_RETURN)) && !this.p.requireSelection) {
            this.processValue();
            e.stop();
        } else if (e.keyCode == 188) {
            e.stop();
        }
    },

    honorReturn: function()
    {
        return (this.aac.knl && !this.aac.knl.getCurrentEntry()) ||
               !this.aac.knl;
    },

    processValue: function()
    {
        var value = $F(this.p.trigger).replace(/^,/, '').strip();
        if (value.length) {
            if (this.addNewItemNode(value)) {
                this.p.onAdd(value);
            }
        }
    },

    resize: function()
    {
        this.input.setStyle({
            width: Math.max(80, $F(this.input).length * 9) + 'px'
        });
    },

    // Used as the updateElement callback.
    updateElement: function(item)
    {
        if (this.addNewItemNode(item)) {
            this.p.onAdd(item);
        }
    },

    /**
     * Adds a new element to the UI, ignoring duplicates.
     *
     * @return boolean True on success, false on failure/duplicate.
     */
    addNewItemNode: function(value)
    {
        var displayValue;

        if (value.empty() || !this.filterChoices([ value ]).size()) {
            return false;
        }

        displayValue = this.p.displayFilter(value);

        this.input.up('li').insert({
            before: new Element('li', { className: this.p.listClassItem })
                .update(displayValue)
                .insert(this.deleteImg().clone(true).show())
                .store('raw', value)
        });
        this.updateInput('');
        this.updateHiddenInput();

        return true;
    },

    deleteImg: function()
    {
        if (!this.dimg) {
            this.dimg = new Element('img', {
                className: this.p.removeClass,
                src: this.p.deleteIcon
            }).hide();
            this.box.insert(this.dimg);
        }

        return this.dimg;
    },

    updateInput: function(input)
    {
        var raw;

        if (Object.isElement(input)) {
            raw = input.retrieve('raw');
            this.removeItemNode(input);
        } else {
            raw = input;
        }
        this.input.setValue(raw);
        this.resize();
    },

    updateHiddenInput: function()
    {
        this.elm.setValue(this.currentValues().join(', '));
    },

    currentEntries: function()
    {
        return this.input.up('ul').select('li.' + this.p.listClassItem);
    },

    currentValues: function()
    {
        return this.currentEntries().invoke('retrieve', 'raw');
    },

    removeItemNode: function(elt)
    {
        var value = elt.remove().retrieve('raw');
        this.updateHiddenInput();
        this.p.onRemove(value);
    },

    disable: function()
    {
      if (!this.enabled || !this.initialized) {
          return;
      }

      this.enabled = false;
      this.box.select('.hordeACItemRemove').invoke('toggle');
      this.input.disable();
    },

    enable: function()
    {
        if (this.enabled) {
            return;
        }
        this.enabled = true;
        this.box.select('.hordeACItemRemove').invoke('toggle');
        this.input.enable();
    },

    clickHandler: function(e)
    {
        var elt = e.element();
        if (elt.hasClassName(this.p.removeClass)) {
            this.removeItemNode(elt.up('li'));
        }
        this.input.focus();
    },

    dblclickHandler: function(e)
    {
        var elt = e.findElement('li');
        if (elt) {
            this.addNewItemNode($F(this.input));
            this.updateInput(elt);
        } else {
            this.input.focus();
        }
        e.stop();
    },

    filterChoices: function(c)
    {
        var cv = this.currentValues();

        return c.select(function(v) {
            return !cv.include(v);
        });
    },

    knlShow: function(l)
    {
        this.input.stopObserving('blur', this.boundProcessValue);
    },

    knlHide: function(l)
    {
        this.input.observe('blur', this.boundProcessValue);
    }
});
