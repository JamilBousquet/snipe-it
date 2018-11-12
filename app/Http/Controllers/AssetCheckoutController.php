<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckoutNotAllowed;
use App\Http\Controllers\CheckInOutRequest;
use App\Http\Requests\AssetCheckoutRequest;
use App\Models\Asset;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AssetCheckoutController extends Controller
{
    use CheckInOutRequest;
    /**
    * Returns a view that presents a form to check an asset out to a
    * user.
    *
    * @author [A. Gianotto] [<snipe@snipe.net>]
    * @param int $assetId
    * @since [v1.0]
    * @return View
    */
    public function create($assetId)
    {
        // Check if the asset exists
        if (is_null($asset = Asset::find(e($assetId)))) {
            // Redirect to the asset management page with error
            return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.does_not_exist'));
        }

        $this->authorize('checkout', $asset);

        if ($asset->availableForCheckout()) {
            return view('hardware/checkout', compact('asset'));
        }
        return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.checkout.not_available'));

        // Get the dropdown of users and then pass it to the checkout view

    }

    /**
     * Validate and process the form data to check out an asset to a user.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param AssetCheckoutRequest $request
     * @param int $assetId
     * @return Redirect
     * @since [v1.0]
     */
    public function store(AssetCheckoutRequest $request, $assetId)
    {
        try {
            // Check if the asset exists
            if (!$asset = Asset::find($assetId)) {
                return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.does_not_exist'));
            } elseif (!$asset->availableForCheckout()) {
                return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.checkout.not_available'));
            }
            $this->authorize('checkout', $asset);
            $admin = Auth::user();

            $target = $this->determineCheckoutTarget($asset);
            if ($asset->is($target)) {
                throw new CheckoutNotAllowed('You cannot check an asset out to itself.');
            }
            $asset = $this->updateAssetLocation($asset, $target);

            $checkout_at = date("Y-m-d H:i:s");
            if (($request->has('checkout_at')) && ($request->get('checkout_at')!= date("Y-m-d"))) {
                $checkout_at = $request->get('checkout_at');
            }

            $expected_checkin = '';
            if ($request->has('expected_checkin')) {
                $expected_checkin = $request->get('expected_checkin');
            }

            if ($asset->checkOut($target, $admin, $checkout_at, $expected_checkin, e($request->get('note')), $request->get('name'))) {
                return redirect()->route("hardware.index")->with('success', trans('admin/hardware/message.checkout.success'));
            }

            // Redirect to the asset management page with error
            return redirect()->to("hardware/$assetId/checkout")->with('error', trans('admin/hardware/message.checkout.error'))->withErrors($asset->getErrors());
        } catch (ModelNotFoundException $e) {
            return redirect()->back()->with('error', trans('admin/hardware/message.checkout.error'))->withErrors($asset->getErrors());
        } catch (CheckoutNotAllowed $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
	
	/**
    *Returns an inline view PDF containing all the information
    *of an asset's (checkin) information.
    *
    *@author [J. Bousquet] []
    *@param int $assetId
    *@since [4.3]
    *@return PDF
    */

    public function getReceipt($assetId, $view = "I")
    {		
        $asset = Asset::find($assetId);
		$line_h = 0.3125;
		
		//lines 683 - 709, 776-782: 
		///Logic to handle logical errors;
		
		///1. Is the asset assigned to a user? 
		/// If not, return to asset page;
		if (!$asset->assignedTo){
			return AssetsController::show($assetId);
		} else {
			$user = $asset->assignedTo;}
		
		$model = $asset->model;
		
		///2. Does the user have a defined location?
		/// If not, assign values as empty string;
		if (Location::find($user->location_id)){
			$location = Location::find($user->location_id);
			$address = $location->address;
			$city = $location->city;
		} else {
			$location = "";
			$address = "";
			$city = "";
		}
		///3. Is the user assigned to a "Department" and "Unit" (respectfully)?
		/// If not, assign values as empty string;
		if ($user->company_id && $user->department_id){
			$company_name = Company::find($user->company_id)->name;
			$department_name = Department::find($user->department_id)->name;
		} else {
			$company_name = "";
			$department_name = "";
		}

        // $pdf = new Fpdf(); < old, deprecated, doesn't work;
        Fpdf::SetDisplayMode("default", "single");
		Fpdf::SetMargins(1, 0.65);
		Fpdf::AddPage();

        // Title;
        $title = "Equipment Dispatch Form";
        Fpdf::SetFont("Arial", "", 21);
        Fpdf::Cell(0, 1, $title, 0, 1, "C");

        // Loan specs;
        $indata1 = array(
                         array("Date: " => $asset->last_checkout, 
                              "Expected Return Date: " => $asset->expected_checkin),
                         
                         array("Item Description: " => $asset->name,
							   "Model: " => $model->name),

                         array("Serial: " => $asset->serial,
							   "Location: " => $address.", ".$city)
						);
		Fpdf::SetFont("Arial", "", 12);
        foreach ($indata1 as $row) {
            foreach ($row as $field => $value) {
                Fpdf::Cell(2.0625, $line_h, $field.$value);
                Fpdf::SetX(Fpdf::GetX() + 0.95);
            }
            Fpdf::Ln(); 
        }
		$purpose = $asset->purpose;
		Fpdf::Write($line_h*0.85, "Purpose: ".$purpose);
		Fpdf::Ln();
        $condline = "Condition: Intact [  ] \t\tDamaged [  ] \t\tPlease Specify:";
        Fpdf::Write($line_h, $condline);
		Fpdf::Ln();
		Fpdf::Ln();
        // User Info Table;
        $tdata = array(array("Department", $company_name),
					   array("Unit", $department_name),
					   array(array("Contact Name", $user->first_name." ".$user->last_name), array("Email", $user->email)),
                       array(array("Tel (Office)", $user->phone), array("(Mobile)", $user->mobile)),
					   );
        for ($i = 0; $i < sizeof($tdata); $i++) {
            if (is_string($tdata[$i][0])) {
				Fpdf::SetFont("Arial", "B", 12);
                Fpdf::Cell(1.1875, $line_h*1.15, $tdata[$i][0], "LTR", 0, "C");
				Fpdf::SetFont("Arial", "", 12);
                Fpdf::Cell(0, $line_h, $tdata[$i][1], "TR", 1, "C");
                continue;
            }
				Fpdf::SetFont("Arial", "B", 12);
				Fpdf::Cell(1.1875, $line_h*1.15, $tdata[$i][0][0], "LTRB", 0, "C");
				Fpdf::SetFont("Arial", "", 12);
				Fpdf::Cell(2.375, $line_h*1.15, $tdata[$i][0][1], "LTRB", 0, "C");
				Fpdf::SetFont("Arial", "B", 12);
				Fpdf::Cell(0.8125, $line_h*1.15, $tdata[$i][1][0], "LTB", 0, "C");
				Fpdf::SetFont("Arial", "", 12);
				Fpdf::Cell(0, $line_h*1.15, $tdata[$i][1][1], "TLRB", 1, "C");
		}
		Fpdf::Ln();
		//Authentication Info;
		$user_Mid = $user->manager_id;
		
		/// 4. Does the user have an assigned Manager?
		/// If not leave as empty string;
		if (User::find($user_Mid)) {
			$Mfirst_name = User::find($user_Mid)->first_name;
			$Mlast_name = User::find($user_Mid)->last_name;
		} else {
			$Mfirst_name = "";
			$Mlast_name = "";
		}
		$authdata = array(array("Approved by:\t\t\t".$Mfirst_name." ".$Mlast_name),
						  array("Loanee Signature: ", "Approver's Signature: "));
		Fpdf::SetFont("Arial", "B", 12);
		foreach ($authdata as $row_col) {
			foreach ($row_col as $field) {
				Fpdf::Cell(2.375, $line_h, $field);
				Fpdf::SetX(Fpdf::GetX() + 0.6875);
			}
			Fpdf::Ln();
			Fpdf::Ln();
		}
        
		//Return Info;
		$return_cell1 = "Returning";
		$return_cell2 = "Returned by: _________________";
		$return_cell3 = "Return Date: _________________";
		$return_cell4 = "Recieved by: _________________";
		Fpdf::SetFont("Arial", "B", 12);
		Fpdf::SetFillColor(229, 224, 224);
		Fpdf::Cell(0, $line_h, $return_cell1, 0, 1, "", true);
		Fpdf::Cell(2.375 + 0.6875, $line_h, $return_cell2, 0, 0, "", true);
		Fpdf::Cell(0, $line_h, $return_cell3, 0, 1, "", true);
		Fpdf::Cell(0, $line_h, $return_cell4, 0, 1, "", true);
		Fpdf::Ln();
		
		// Fpdf::Write($line_h, $asset);


        //Save and show in browser [and "return void" or end];
		Fpdf::Output($view, "NICTC_AssetMgmt_EDF_{$user->first_name}_{$user->last_name}_-_{$asset->name}.pdf");

    }

}
