<?php namespace Gloudemans\Shoppingcart;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

class Cart {

	/**
	 * Session class instance
	 *
	 * @var Illuminate\Session\SessionManager
	 */
	protected $session;

	/**
	 * Event class instance
	 *
	 * @var Illuminate\Events\Dispatcher
	 */
	protected $event;

	/**
	 * Current cart instance
	 *
	 * @var string
	 */
	protected $instance;

	/**
	 * The Eloquent model a cart is associated with
	 *
	 * @var string
	 */
	protected $associatedModel;

	/**
	 * An optional namespace for the associated model
	 *
	 * @var string
	 */
	protected $associatedModelNamespace;

	/**
	 * Constructor
	 *
	 * @param Illuminate\Session\SessionManager $session Session class instance
	 * @param \Illuminate\Contracts\Events\Dispatcher $event Event class instance
	 */
	public function __construct($session, Dispatcher $event)
	{
		$this->session = $session;
		$this->event = $event;

		$this->instance = 'main';
	}

    /**
     * Set the current cart instance
     *
     * @param  string $instance Cart instance name
     * @return Cart
     * @throws Exceptions\ShoppingcartInstanceException
     */
	public function instance($instance = null)
	{
		if(empty($instance)) throw new Exceptions\ShoppingcartInstanceException;

		$this->instance = $instance;

		// Return self so the method is chainable
		return $this;
	}

    /**
     * Set the associated model
     *
     * @param  string $modelName The name of the model
     * @param  string $modelNamespace The namespace of the model
     * @return $this
     * @throws Exceptions\ShoppingcartUnknownModelException
     */
	public function associate($modelName, $modelNamespace = null)
	{
		$this->associatedModel = $modelName;
		$this->associatedModelNamespace = $modelNamespace;

		if( ! class_exists($modelNamespace . '\\' . $modelName)) throw new Exceptions\ShoppingcartUnknownModelException;

		// Return self so the method is chainable
		return $this;
	}

    /**
     * Add a row to the cart
     *
     * @param string|array $id Unique ID of the item|Item formated as array|Array of items
     * @param string $name Name of the item
     * @param int $qty Item qty to add to the cart
     * @param float $price Price of one item
     * @param float $weight Weight of one item
     * @param array $options Array of additional options, such as 'size' or 'color'
     * @return array
     */
	public function add($id, $name = null, $qty = null, $price = null,$weight =null, array $options = [])
	{
		// If the first parameter is an array we need to call the add() function again
		if(is_array($id))
		{
			// And if it's not only an array, but a multidimensional array, we need to
			// recursively call the add function
			if($this->is_multi($id))
			{
				// Fire the cart.batch event
				$this->event->fire('cart.batch', $id);
                $results=[];
				foreach($id as $item)
				{
					$options = array_get($item, 'options', []);
					$results[]=$this->addRow($item['id'], $item['name'], $item['qty'], $item['price'],$item['weight'], $options);
				}

				// Fire the cart.batched event
				$this->event->fire('cart.batched', $id);

				return $results;
			}

			$options = array_get($id, 'options', []);

			// Fire the cart.add event
			$this->event->fire('cart.add', array_merge($id, ['options' => $options]));

			$result = $this->addRow($id['id'], $id['name'], $id['qty'], $id['price'],$id['weight'], $options);

			// Fire the cart.added event
			$this->event->fire('cart.added', array_merge($id, ['options' => $options]));

			return $result;
		}

		// Fire the cart.add event
		$this->event->fire('cart.add', compact('id', 'name', 'qty', 'price','weight', 'options'));

		$result = $this->addRow($id, $name, $qty, $price,$weight, $options);

		// Fire the cart.added event
		$this->event->fire('cart.added', compact('id', 'name', 'qty', 'price','weight', 'options'));

		return $result;
	}

    /**
     * Update the quantity of one row of the cart
     *
     * @param  string $rowId The rowid of the item you want to update
     * @param  integer|array $attribute New quantity of the item|Array of attributes to update
     * @return bool
     * @throws Exceptions\ShoppingcartInvalidRowIDException
     */
	public function update($rowId, $attribute)
	{
		if( ! $this->hasRowId($rowId)) throw new Exceptions\ShoppingcartInvalidRowIDException;

		if(is_array($attribute))
		{
			// Fire the cart.update event
			$this->event->fire('cart.update', $rowId);

			$result = $this->updateAttribute($rowId, $attribute);

			// Fire the cart.updated event
			$this->event->fire('cart.updated', $rowId);

			return $result;
		}

		// Fire the cart.update event
		$this->event->fire('cart.update', $rowId);

		$result = $this->updateQty($rowId, $attribute);

		// Fire the cart.updated event
		$this->event->fire('cart.updated', $rowId);

		return $result;
	}

    /**
     * Remove a row from the cart
     *
     * @param  string $rowId The rowid of the item
     * @return bool
     * @throws Exceptions\ShoppingcartInvalidRowIDException
     */
	public function remove($rowId)
	{
		if( ! $this->hasRowId($rowId)) throw new Exceptions\ShoppingcartInvalidRowIDException;

		$cart = $this->getContent();

		// Fire the cart.remove event
		$this->event->fire('cart.remove', $rowId);

		$cart->forget($rowId);

		// Fire the cart.removed event
		$this->event->fire('cart.removed', $rowId);

		return $this->updateCart($cart);
	}

	/**
	 * Get a row of the cart by its ID
	 *
	 * @param  string  $rowId  The ID of the row to fetch
	 * @return CartCollection
	 */
	public function get($rowId)
	{
		$cart = $this->getContent();

		return ($cart->has($rowId)) ? $cart->get($rowId) : NULL;
	}

	/**
	 * Get the cart content
	 *
	 * @return CartRowCollection
	 */
	public function content()
	{
		$cart = $this->getContent();

		return (empty($cart)) ? NULL : $cart;
	}

	/**
	 * Empty the cart
	 *
	 * @return boolean
	 */
	public function destroy()
	{
		// Fire the cart.destroy event
		$this->event->fire('cart.destroy');

		$result = $this->updateCart(NULL);

		// Fire the cart.destroyed event
		$this->event->fire('cart.destroyed');

		return $result;
	}

	/**
	 * Get the price total
	 *
	 * @return float
	 */
	public function total()
	{
		$total = 0;
		$cart = $this->getContent();

		if(empty($cart))
		{
			return $total;
		}

		foreach($cart AS $row)
		{
			$total += $row->subtotal;
		}

		return $total;
	}
    /**
     * Get the total weight
     *
     * @return float
     */
    public function totalweight()
    {
        $totalweight = 0;
        $cart = $this->getContent();

        if(empty($cart))
        {
            return $totalweight;
        }

        foreach($cart AS $row)
        {
            $totalweight += $row->subtotalweight;
        }

        return $totalweight;
    }
	/**
	 * Get the number of items in the cart
	 *
	 * @param  boolean  $totalItems  Get all the items (when false, will return the number of rows)
	 * @return int
	 */
	public function count($totalItems = true)
	{
		$cart = $this->getContent();

		if( ! $totalItems)
		{
			return $cart->count();
		}

		$count = 0;

		foreach($cart AS $row)
		{
			$count += $row->qty;
		}

		return $count;
	}

	/**
	 * Search if the cart has a item
	 *
	 * @param  array  $search  An array with the item ID and optional options
	 * @return array|boolean
	 */
	public function search(array $search)
	{
		if(empty($search)) return false;

		foreach($this->getContent() as $item)
		{
            /** @var CartCollection $item */
            $found = $item->search($search);

			if($found)
			{
				$rows[] = $item->rowid;
			}
		}

		return (empty($rows)) ? false : $rows;
	}

    /**
     * Add row to the cart
     *
     * @param string $id Unique ID of the item
     * @param string $name Name of the item
     * @param int $qty Item qty to add to the cart
     * @param float $price Price of one item
     * @param float $weight Weight of one item
     * @param array $options Array of additional options, such as 'size' or 'color'
     * @throws Exceptions\ShoppingcartInvalidItemException
     * @throws Exceptions\ShoppingcartInvalidPriceException
     * @throws Exceptions\ShoppingcartInvalidQtyException
     * @throws Exceptions\ShoppingcartInvalidWeightException
     */
	protected function addRow($id, $name, $qty, $price,$weight, array $options = [])
	{
		if(empty($id) || empty($name) || empty($qty) || ! isset($price) || ! isset($weight))
		{
			throw new Exceptions\ShoppingcartInvalidItemException;
		}

		if( ! is_numeric($qty))
		{
			throw new Exceptions\ShoppingcartInvalidQtyException;
		}

		if( ! is_numeric($price))
		{
			throw new Exceptions\ShoppingcartInvalidPriceException;
		}
        if( ! is_numeric($weight))
        {
            throw new Exceptions\ShoppingcartInvalidWeightException;
        }
		$cart = $this->getContent();

		$rowId = $this->generateRowId($id, $options);

		if($cart->has($rowId))
		{
			$row = $cart->get($rowId);
			$cart = $this->updateRow($rowId, ['qty' => $row->qty + $qty]);
		}
		else
		{
			$cart = $this->createRow($rowId, $id, $name, $qty, $price,$weight, $options);
		}

		return $this->updateCart($cart);
	}

	/**
	 * Generate a unique id for the new row
	 *
	 * @param  string  $id       Unique ID of the item
	 * @param  array   $options  Array of additional options, such as 'size' or 'color'
	 * @return boolean
	 */
	protected function generateRowId($id, $options)
	{
		ksort($options);

		return md5($id . serialize($options));
	}

	/**
	 * Check if a rowid exists in the current cart instance
	 *
	 * @param  string  $rowId  Unique ID of the item
	 * @return boolean
	 */
	protected function hasRowId($rowId)
	{
		return $this->getContent()->has($rowId);
	}

	/**
	 * Update the cart
	 *
	 * @param  CartCollection  $cart  The new cart content
	 */
	protected function updateCart($cart)
	{
		return $this->session->put($this->getInstance(), $cart);
	}

	/**
	 * Get the carts content, if there is no cart content set yet, return a new empty Collection
	 *
	 * @return CartCollection
	 */
	protected function getContent()
	{
		$content = ($this->session->has($this->getInstance())) ? $this->session->get($this->getInstance()) : new CartCollection;

		return $content;
	}

	/**
	 * Get the current cart instance
	 *
	 * @return string
	 */
	protected function getInstance()
	{
		return 'cart.' . $this->instance;
	}

    /**
     * Update a row if the rowId already exists
     *
     * @param  string $rowId The ID of the row to update
     * @param $attributes
     * @return CartCollection
     * @internal param int $qty The quantity to add to the row
     */
	protected function updateRow($rowId, $attributes)
	{
		$cart = $this->getContent();

		$row = $cart->get($rowId);

		foreach($attributes as $key => $value)
		{
			if($key == 'options')
			{
				$options = $row->options->merge($value);
				$row->put($key, $options);
			}
			else
			{
				$row->put($key, $value);
			}
		}

		if( ! is_null(array_keys($attributes, ['qty', 'price'])))
		{
			$row->put('subtotal', $row->qty * $row->price);
		}

        if( ! is_null(array_keys($attributes, ['qty', 'weight'])))
        {
            $row->put('subtotalweight', $row->qty * $row->weight);
        }

        $cart->put($rowId, $row);

		return $cart;
	}

    /**
     * Create a new row Object
     *
     * @param  string $rowId The ID of the new row
     * @param  string $id Unique ID of the item
     * @param  string $name Name of the item
     * @param  int $qty Item qty to add to the cart
     * @param  float $price Price of one item
     * @param  float $weight Weight of one item
     * @param  array $options Array of additional options, such as 'size' or 'color'
     * @return CartCollection
     */
	protected function createRow($rowId, $id, $name, $qty, $price,$weight, $options)
	{
		$cart = $this->getContent();

		$newRow = new CartRowCollection([
			'rowid' => $rowId,
			'id' => $id,
			'name' => $name,
			'qty' => $qty,
			'price' => $price,
            'weight' => $weight,
			'options' => new CartRowOptionsCollection($options),
			'subtotal' => $qty * $price,
            'subtotalweight' => $qty * $weight
		], $this->associatedModel, $this->associatedModelNamespace);

		$cart->put($rowId, $newRow);

		return $cart;
	}

	/**
	 * Update the quantity of a row
	 *
	 * @param  string  $rowId  The ID of the row
	 * @param  int     $qty    The qty to add
	 * @return CartCollection
	 */
	protected function updateQty($rowId, $qty)
	{
		if($qty <= 0)
		{
			return $this->remove($rowId);
		}

		return $this->updateRow($rowId, ['qty' => $qty]);
	}

	/**
	 * Update an attribute of the row
	 *
	 * @param  string  $rowId       The ID of the row
	 * @param  array   $attributes  An array of attributes to update
	 * @return CartCollection
	 */
	protected function updateAttribute($rowId, $attributes)
	{
		return $this->updateRow($rowId, $attributes);
	}

	/**
	 * Check if the array is a multidimensional array
	 *
	 * @param  array   $array  The array to check
	 * @return boolean
	 */
	protected function is_multi(array $array)
	{
		return is_array(head($array));
	}

}
